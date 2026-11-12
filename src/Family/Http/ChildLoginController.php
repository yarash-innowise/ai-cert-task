<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\EnableChildLogin;
use App\Family\Domain\PlayerProfileRepository;
use App\Shared\Domain\Email;
use App\Shared\Http\ClientIp;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Http\Redirect;
use App\Shared\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The parent sets up a child's own login (G1). There is no public route that creates a child account. */
final class ChildLoginController
{
    use ActorAware;

    private const TEMPLATE = 'family/children/login.twig';

    public function __construct(
        private readonly View $view,
        private readonly Redirect $redirect,
        private readonly EnableChildLogin $enableLogin,
        private readonly PlayerProfileRepository $profiles,
        private readonly ClientIp $clientIp,
    ) {}

    /** @param array<string, string> $args */
    public function form(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $profile = $this->profiles->findOwned((string) ($args['id'] ?? ''), $this->actor($request)->id);
        if ($profile === null || !$profile->isChild() || $profile->loginUserId !== null) {
            throw new NotFound();
        }

        return $this->view->render(self::TEMPLATE, ['page_title' => 'Login for ' . $profile->firstName, 'profile' => $profile, 'old' => [], 'errors' => []]);
    }

    /** @param array<string, string> $args */
    public function submit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $profile = $this->profiles->findOwned((string) ($args['id'] ?? ''), $actor->id);
        if ($profile === null || !$profile->isChild()) {
            throw new NotFound();
        }
        $body = $this->body($request);
        $rawEmail = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        try {
            $this->enableLogin->execute($actor, $profile->id, Email::fromString($rawEmail), $password, $this->clientIp->fromRequest($request));
        } catch (ValidationFailed $e) {
            // Never echo the password back.
            return $this->view->render(self::TEMPLATE, ['page_title' => 'Login for ' . $profile->firstName, 'profile' => $profile, 'old' => ['email' => trim($rawEmail)], 'errors' => $e->errors], 422);
        }

        return $this->redirect->to('/family/children?notice=login_enabled');
    }
}
