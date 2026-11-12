<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

final class EventsPlaceholderTest extends AppHttpTestCase
{
    #[Group('AC-46')]
    public function testAPlayerSeesTheViewOnlyEventsPlaceholder(): void
    {
        $this->signInAs('player');

        $response = $this->get('/family/events');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Events</h1>', $this->body($response));
        self::assertStringContainsString('No events are available yet', $this->body($response));
        self::assertStringNotContainsString('<form', preg_replace('#<header.*?</header>#s', '', $this->body($response)) ?? '');
    }

    #[Group('AC-46')]
    public function testATrainerWithAnOrganizationSeesThePlaceholder(): void
    {
        $id = $this->createUser('trainer', 't@example.com');
        $this->pdo->prepare('INSERT INTO organizations (owner_user_id, business_name) VALUES (:o, :n)')->execute(['o' => $id, 'n' => 'Org']);
        $this->login('t@example.com');

        $response = $this->get('/trainer/events');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No events are available yet', $this->body($response));
    }

    #[Group('AC-9')]
    public function testTheEventsPagesAreRoleGated(): void
    {
        $this->signInAs('coach');
        self::assertSame(403, $this->get('/family/events')->getStatusCode());
        self::assertSame(403, $this->get('/trainer/events')->getStatusCode());

        $this->cookies = [];
        $this->signInAs('player');
        $playerOnTrainerPage = $this->get('/trainer/events');
        self::assertSame(403, $playerOnTrainerPage->getStatusCode());

        $this->cookies = [];
        self::assertSame(302, $this->get('/family/events')->getStatusCode());
    }
}
