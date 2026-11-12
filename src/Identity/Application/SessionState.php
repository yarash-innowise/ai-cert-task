<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Session;
use App\Identity\Domain\SessionRepository;
use App\Shared\Domain\Clock;

/**
 * The session of one request. Handlers ask it to start, regenerate or end the session; the
 * SessionMiddleware turns what happened into the cookie on the response.
 */
final class SessionState
{
    private ?string $issuedToken = null;
    private bool $ended = false;

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly Clock $clock,
        private ?Session $session,
        private bool $clearCookie = false,
    ) {}

    public function session(): ?Session
    {
        return $this->session;
    }

    public function userId(): ?string
    {
        return $this->session?->userId;
    }

    /** Starts a fresh session for the user (always a new identifier, so a pre-login id is never reused). */
    public function start(string $userId): void
    {
        if ($this->session !== null) {
            $this->sessions->delete($this->session->tokenHash);
        }

        $token = Session::newToken();
        $now = $this->clock->now();
        $session = new Session(Session::hashToken($token), $userId, Session::newToken(), $now, $now);
        $this->sessions->add($session);

        $this->session = $session;
        $this->issuedToken = $token;
        $this->ended = false;
        $this->clearCookie = false;
    }

    /**
     * Remembers the chosen context (organization and acting profile) for the rest of the session.
     * The caller has already checked that the user may act in it; ContextMiddleware re-checks on every request.
     */
    public function setActiveContext(string $organizationId, ?string $profileId): void
    {
        if ($this->session === null) {
            throw new \LogicException('There is no session to store a context in.');
        }
        $context = ['organization_id' => $organizationId, 'profile_id' => $profileId];
        $this->sessions->setActiveContext($this->session->tokenHash, $context);
        $this->session = $this->session->withActiveContext($context);
    }

    /** Gives the current session a new identifier and CSRF token (login, logout, privilege change). */
    public function regenerate(): void
    {
        if ($this->session === null) {
            throw new \LogicException('There is no session to regenerate.');
        }

        $token = Session::newToken();
        $hash = Session::hashToken($token);
        $csrf = Session::newToken();
        $this->sessions->rotate($this->session->tokenHash, $hash, $csrf);

        $this->session = $this->session->withToken($hash, $csrf);
        $this->issuedToken = $token;
    }

    /**
     * Turns the session into the target user's view for the administrator (impersonation start). The identifier and
     * CSRF token are regenerated, so nothing issued to the administrator's own session carries over.
     */
    public function beginImpersonation(string $targetUserId, string $impersonationId): void
    {
        if ($this->session === null || $this->session->isImpersonating()) {
            throw new \LogicException('There is no plain session to impersonate from.');
        }

        $next = $this->session->impersonating($targetUserId, $impersonationId, $this->session->userId);
        $this->sessions->convert($this->session->tokenHash, $targetUserId, $impersonationId, $this->session->userId);
        $this->session = $next;
        $this->regenerate();
    }

    /** Returns the session to the administrator's own view (impersonation end), with a regenerated identifier. */
    public function endImpersonation(): void
    {
        if ($this->session === null || !$this->session->isImpersonating()) {
            throw new \LogicException('The session is not impersonating.');
        }

        $back = $this->session->returnedToImpersonator();
        $this->sessions->convert($this->session->tokenHash, $back->userId, null, null);
        $this->session = $back;
        $this->regenerate();
    }

    public function end(): void
    {
        if ($this->session !== null) {
            $this->sessions->delete($this->session->tokenHash);
        }
        $this->session = null;
        $this->issuedToken = null;
        $this->ended = true;
        $this->clearCookie = true;
    }

    /** Plaintext token that must be sent to the browser, when one was issued during this request. */
    public function issuedToken(): ?string
    {
        return $this->issuedToken;
    }

    public function shouldClearCookie(): bool
    {
        return $this->clearCookie;
    }

    public function wasEnded(): bool
    {
        return $this->ended;
    }
}
