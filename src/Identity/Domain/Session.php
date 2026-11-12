<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;

final readonly class Session
{
    /** @param array<string, mixed>|null $activeContext */
    public function __construct(
        public string $tokenHash,
        public string $userId,
        public string $csrfToken,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastSeenAt,
        public ?array $activeContext = null,
        public ?string $impersonationId = null,
        public ?string $impersonatorUserId = null,
    ) {}

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function withLastSeen(DateTimeImmutable $at): self
    {
        return new self($this->tokenHash, $this->userId, $this->csrfToken, $this->createdAt, $at, $this->activeContext, $this->impersonationId, $this->impersonatorUserId);
    }

    /** @param array<string, mixed>|null $context */
    public function withActiveContext(?array $context): self
    {
        return new self($this->tokenHash, $this->userId, $this->csrfToken, $this->createdAt, $this->lastSeenAt, $context, $this->impersonationId, $this->impersonatorUserId);
    }

    public function withToken(string $tokenHash, string $csrfToken): self
    {
        return new self($tokenHash, $this->userId, $csrfToken, $this->createdAt, $this->lastSeenAt, $this->activeContext, $this->impersonationId, $this->impersonatorUserId);
    }

    /** The same session, now showing the target user's view for the administrator who started it. */
    public function impersonating(string $targetUserId, string $impersonationId, string $impersonatorUserId): self
    {
        return new self($this->tokenHash, $targetUserId, $this->csrfToken, $this->createdAt, $this->lastSeenAt, null, $impersonationId, $impersonatorUserId);
    }

    /** The same session, back in the administrator's own view. */
    public function returnedToImpersonator(): self
    {
        if ($this->impersonatorUserId === null) {
            throw new \LogicException('The session is not impersonating.');
        }

        return new self($this->tokenHash, $this->impersonatorUserId, $this->csrfToken, $this->createdAt, $this->lastSeenAt);
    }

    public function isImpersonating(): bool
    {
        return $this->impersonationId !== null;
    }
}
