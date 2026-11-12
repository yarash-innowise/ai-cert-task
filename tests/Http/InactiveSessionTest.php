<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

#[Group('AC-73')]
final class InactiveSessionTest extends AppHttpTestCase
{
    public function testDeactivatedUsersValidSessionEndsAtTheNextRequest(): void
    {
        $id = $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        self::assertSame(200, $this->get('/family')->getStatusCode());

        $this->pdo->exec("UPDATE users SET status = 'inactive' WHERE id = '{$id}'");

        $response = $this->get('/family');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login?notice=deactivated', $this->location($response));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $id]), 'session row is gone');
        self::assertArrayNotHasKey('__Host-sid', $this->cookies);

        // the login page then says why, and signing in again is refused with the same message
        self::assertStringContainsString('Account deactivated. Contact support.', $this->body($this->get($this->location($response))));
        self::assertStringContainsString('Account deactivated. Contact support.', $this->body($this->login('who@example.com')));
    }

    public function testDeletedUsersSessionEndsToo(): void
    {
        $id = $this->createUser('coach', 'who@example.com');
        $this->login('who@example.com');
        $this->pdo->exec("UPDATE users SET status = 'deleted' WHERE id = '{$id}'");

        self::assertSame(302, $this->get('/coach')->getStatusCode());
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
    }

    public function testEveryOpenSessionOfTheUserEndsNotJustOne(): void
    {
        $id = $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $phone = $this->cookies;
        $this->cookies = [];
        $this->login('who@example.com');
        $laptop = $this->cookies;
        $this->pdo->exec("UPDATE users SET status = 'inactive' WHERE id = '{$id}'");

        foreach ([$phone, $laptop] as $jar) {
            self::assertSame(302, $this->get('/family', [], $jar)->getStatusCode());
        }
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
    }
}
