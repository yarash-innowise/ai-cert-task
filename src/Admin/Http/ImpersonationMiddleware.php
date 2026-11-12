<?php

declare(strict_types=1);

namespace App\Admin\Http;

use App\Admin\Application\EndImpersonation;
use App\Admin\Domain\Impersonation;
use App\Admin\Domain\ImpersonationContext;
use App\Admin\Domain\ImpersonationEndReason;
use App\Admin\Domain\ImpersonationRepository;
use App\Identity\Application\SessionState;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Identity\Http\CookieHeader;
use App\Shared\Domain\Clock;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Redirect;
use App\Shared\Infrastructure\Audit\AuditLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces and records an impersonation (design C2, C12; AC-52..AC-55). The effective CurrentUser is already the
 * impersonated user (the session row points at them), so navigation, permissions and data match exactly. Here:
 *  - the impersonation ends at start + 1 hour, when the session-only `__Host-imp` marker cookie is gone (the
 *    browser was closed) or when the impersonated user was deleted; the administrator then lands in their own
 *    view with a notice and the end is logged with its reason;
 *  - an inactive user is viewed read-only (Q-01.16): every state-changing request is refused;
 *  - every state-changing request is audit-logged with the administrator's identity.
 */
final class ImpersonationMiddleware implements MiddlewareInterface
{
    public const COOKIE = '__Host-imp';
    public const ATTRIBUTE = 'impersonation';
    public const EXIT_PATH = '/impersonation/exit';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    /** Choosing a password is the user's own act; an administrator viewing as them never does it. */
    private const REFUSED_WHILE_IMPERSONATING = ['/password/set'];
    private const ALLOWED_WHILE_READ_ONLY = [self::EXIT_PATH, '/logout'];

    public function __construct(
        private readonly ImpersonationRepository $impersonations,
        private readonly EndImpersonation $end,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly Redirect $redirect,
        private readonly ClientIp $clientIp,
        private readonly ImpersonationHolder $holder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = $request->getAttribute('session_state');
        $session = $state instanceof SessionState ? $state->session() : null;
        $user = $request->getAttribute('current_user');
        if (!$state instanceof SessionState || $session === null || !$session->isImpersonating() || !$user instanceof CurrentUser) {
            $this->holder->set(null);

            return $handler->handle($request->withAttribute(self::ATTRIBUTE, null));
        }

        $now = $this->clock->now();
        $ip = $this->clientIp->fromRequest($request);
        $impersonation = $this->impersonations->find((string) $session->impersonationId);
        $admin = $this->users->findById((string) $session->impersonatorUserId);
        $target = $this->users->findById($session->userId);

        if ($admin === null || !$admin->isActive()) {
            // The administrator lost the account meanwhile: the whole session goes, not just the impersonation.
            if ($impersonation !== null) {
                $this->impersonations->end($impersonation->id, $now, ImpersonationEndReason::SessionExpired);
            }
            $state->end();
            $this->holder->set(null);

            return $this->redirect->to('/login?notice=deactivated')->withAddedHeader('Set-Cookie', CookieHeader::clear(self::COOKIE));
        }

        [$reason, $endedAt] = $this->endingOf($impersonation, $target?->status, $request, $now);
        if ($reason !== null) {
            return $this->ended($state, $impersonation, $reason, $endedAt, $ip);
        }
        if ($impersonation === null || $target === null) {
            throw new \LogicException('An open impersonation always has its log row and target.');
        }

        $this->impersonations->touch($impersonation->id, $now);
        $context = new ImpersonationContext(
            $impersonation->id,
            $admin->id,
            $admin->name,
            $target->id,
            $target->name,
            $target->role->label(),
            $impersonation->startedAt,
            $impersonation->expiresAt(),
            !$target->isActive(),
        );
        $this->holder->set($context);

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        if (!in_array($method, self::SAFE_METHODS, true)) {
            $refused = in_array($path, self::REFUSED_WHILE_IMPERSONATING, true)
                || ($context->readOnly && !in_array($path, self::ALLOWED_WHILE_READ_ONLY, true));
            // Leaving is not an action taken as the user: it is recorded by the `impersonation.ended` entry.
            if (!in_array($path, [self::EXIT_PATH, '/logout'], true)) {
                $this->audit->log('impersonation.action', $target->id, 'request', null, null, [
                    'impersonation_id' => $impersonation->id,
                    'method' => $method,
                    'path' => self::redact($path),
                    'refused' => $refused,
                ], $admin->id, $ip);
            }
            if ($refused) {
                throw new Forbidden();
            }
            if ($path === '/logout') {
                // Signing out while impersonating ends the impersonation first, so its log row is closed.
                $this->end->execute($state, $impersonation, ImpersonationEndReason::Logout, null, $ip);

                return $handler->handle($request->withAttribute(self::ATTRIBUTE, null))
                    ->withAddedHeader('Set-Cookie', CookieHeader::clear(self::COOKIE));
            }
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $context));
    }

    /**
     * @return array{ImpersonationEndReason|null, \DateTimeImmutable|null} why the impersonation is over now, and when it really ended
     */
    private function endingOf(?Impersonation $impersonation, ?UserStatus $targetStatus, ServerRequestInterface $request, \DateTimeImmutable $now): array
    {
        if ($impersonation === null || !$impersonation->isOpen()) {
            // The sweep closed it already (or the row is gone): only the session still has to come back.
            return $impersonation === null
                ? [ImpersonationEndReason::Timeout, null]
                : [$impersonation->endReason ?? ImpersonationEndReason::Timeout, $impersonation->endedAt];
        }
        if ($impersonation->hasExpired($now)) {
            return [ImpersonationEndReason::Timeout, $impersonation->expiresAt()];
        }
        if (($request->getCookieParams()[self::COOKIE] ?? null) !== $impersonation->id) {
            return [ImpersonationEndReason::BrowserClosed, $impersonation->lastActivityAt];
        }
        if ($targetStatus === null || $targetStatus === UserStatus::Deleted) {
            return [ImpersonationEndReason::TargetUnavailable, $now];
        }

        return [null, null];
    }

    private function ended(SessionState $state, ?Impersonation $impersonation, ImpersonationEndReason $reason, ?\DateTimeImmutable $endedAt, string $ip): ResponseInterface
    {
        if ($impersonation !== null) {
            $this->end->execute($state, $impersonation, $reason, $endedAt, $ip);
        } else {
            $state->endImpersonation();
        }
        $this->holder->set(null);

        $notice = match ($reason) {
            ImpersonationEndReason::Timeout => 'impersonation_timeout',
            ImpersonationEndReason::BrowserClosed => 'impersonation_browser_closed',
            ImpersonationEndReason::TargetUnavailable => 'impersonation_target_deleted',
            default => 'impersonation_ended',
        };

        return $this->redirect->to('/admin/users?notice=' . $notice)->withAddedHeader('Set-Cookie', CookieHeader::clear(self::COOKIE));
    }

    /** Keeps the shape of the address (lowercase words and ids) and hides everything that could be a secret. */
    public static function redact(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $keep = $segment === ''
                || preg_match('/^[a-z][a-z0-9_-]{0,23}$/', $segment) === 1
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1;
            $segments[] = $keep ? $segment : '*';
        }

        return implode('/', $segments);
    }
}
