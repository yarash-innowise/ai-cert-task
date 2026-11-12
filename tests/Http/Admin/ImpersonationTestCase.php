<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use Psr\Http\Message\ResponseInterface;

/** Fixtures for the impersonation tests: a Coach (Cora) who is a member of the fixture organization, and the signed-in Super Admin. */
abstract class ImpersonationTestCase extends LifecycleTestCase
{
    protected string $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coach = $this->activeCoach();
    }

    protected function impersonate(string $userId): ResponseInterface
    {
        return $this->post("/admin/users/{$userId}/impersonate");
    }

    protected function startAsCoach(): void
    {
        $response = $this->impersonate($this->coach);
        self::assertSame(302, $response->getStatusCode(), $this->body($response));
    }

    /** @return array<string, mixed> */
    protected function impersonationRow(): array
    {
        return $this->row('SELECT * FROM impersonations ORDER BY started_at DESC, id LIMIT 1') ?? self::fail('no impersonation was logged');
    }

    /** @return array<string, mixed> the only session row of the current cookie */
    protected function sessionRow(): array
    {
        return $this->row('SELECT * FROM sessions WHERE token_hash = :h', ['h' => \App\Identity\Domain\Session::hashToken($this->cookies['__Host-sid'] ?? '')]) ?? self::fail('no session');
    }

    protected function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }
}
