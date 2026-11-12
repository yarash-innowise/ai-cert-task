<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use Psr\Http\Message\ResponseInterface;
use Tests\Http\Approval\ApprovalHttpTestCase;

/**
 * Fixtures for the Users tool lifecycle and impersonation tests: the approval fixtures (Trainer "Ace Tennis
 * Academy", parent "Pat Parent", child "Mia" with a login) plus a Super Admin who is signed in at the start.
 */
abstract class LifecycleTestCase extends ApprovalHttpTestCase
{
    protected string $admin;
    protected const ADMIN_EMAIL = 'ada.admin@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->signInAs('super_admin', self::ADMIN_EMAIL, 'Ada Admin');
    }

    protected function deactivate(string $userId): ResponseInterface
    {
        return $this->post("/admin/users/{$userId}/deactivate");
    }

    protected function reactivate(string $userId): ResponseInterface
    {
        return $this->post("/admin/users/{$userId}/reactivate");
    }

    protected function deleteUser(string $userId, string $reason = 'Privacy request from the account holder.'): ResponseInterface
    {
        return $this->post("/admin/users/{$userId}/delete", ['reason' => $reason]);
    }

    protected function statusOf(string $userId): string
    {
        return (string) $this->scalar('SELECT status FROM users WHERE id = :i', ['i' => $userId]);
    }

    protected function emailOf(string $userId): string
    {
        return (string) $this->scalar('SELECT email FROM users WHERE id = :i', ['i' => $userId]);
    }

    protected function requestStatus(string $requestId): string
    {
        return (string) $this->scalar('SELECT status FROM approval_requests WHERE id = :i', ['i' => $requestId]);
    }

    /**
     * Signs a user in with a fresh cookie jar (the default test password) and returns that jar.
     *
     * @return array<string, string>
     */
    protected function signInWith(string $email): array
    {
        $this->cookies = [];
        $this->login($email);

        return $this->cookies;
    }

    /** @return string user id of a Coach who is an active member of the fixture organization */
    protected function activeCoach(string $email = 'coach@example.com', string $name = 'Cora Coach'): string
    {
        $coach = $this->createUser('coach', $email, name: $name);
        $this->pdo->prepare("INSERT INTO coach_memberships (organization_id, coach_user_id, status) VALUES (:o, :c, 'active')")
            ->execute(['o' => $this->org, 'c' => $coach]);

        return $coach;
    }

    protected function trainerEmail(): string
    {
        return $this->emailOf($this->trainer);
    }
}
