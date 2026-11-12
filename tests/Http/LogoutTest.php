<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

#[Group('AC-8')]
final class LogoutTest extends AppHttpTestCase
{
    public function testLogoutEndsTheSessionOnTheServerAndRequiresAFreshLogin(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $oldCookie = $this->cookies;
        self::assertSame(200, $this->get('/family')->getStatusCode());

        $response = $this->post('/logout');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($response));
        self::assertSame(0, $this->scalar('SELECT count(*) FROM sessions'));
        self::assertStringContainsString('Max-Age=0', implode(';', $response->getHeader('Set-Cookie')));

        // a stolen copy of the old cookie is useless
        self::assertSame(302, $this->get('/family', [], $oldCookie)->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($this->get('/family')));
    }

    public function testLoginAlwaysIssuesANewSessionIdentifier(): void
    {
        $this->createUser('player', 'who@example.com');
        $this->login('who@example.com');
        $first = $this->cookie('__Host-sid');
        $this->cookies = [];
        $this->login('who@example.com');

        self::assertNotNull($first);
        self::assertNotSame($first, $this->cookie('__Host-sid'));
    }

    public function testLogoutIsNotPossibleAnonymously(): void
    {
        $response = $this->post('/logout');

        self::assertStringStartsWith('/login', $this->location($response));
    }
}
