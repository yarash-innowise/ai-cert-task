<?php

declare(strict_types=1);

namespace Tests\Http\Availability;

use PHPUnit\Framework\Attributes\Group;

/** Who may edit a player profile's week: the owner only (AC-9, AC-47, AC-10). */
final class PlayerAvailabilityPolicyTest extends AvailabilityHttpTestCase
{
    private string $parent;
    private string $mia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = $this->createUser('player', 'parent@example.com', name: 'Pat Parent');
        $this->mia = $this->childOf($this->parent, 'Mia', 'Smith', '2018-05-01');
    }

    #[Group('AC-61')]
    public function testAnotherFamilyCannotReadOrChangeTheWeek(): void
    {
        $this->createUser('player', 'other@example.com', name: 'Olga Other');
        $this->cookies = [];
        $this->login('other@example.com');

        self::assertSame(404, $this->get('/family/availability/' . $this->mia)->getStatusCode());
        self::assertSame(404, $this->post('/family/availability/' . $this->mia, $this->week([0 => [['17:00', '19:00']]]))->getStatusCode());
        self::assertSame(0, $this->slotCount('player_profile', $this->mia));
    }

    #[Group('AC-61'), Group('AC-47')]
    public function testAChildLoginCannotEditAvailability(): void
    {
        $this->signInAsChildLogin($this->parent, $this->mia);

        self::assertSame(403, $this->get('/family/availability/' . $this->mia)->getStatusCode());
        self::assertSame(403, $this->get('/family/availability')->getStatusCode());
        self::assertSame(403, $this->post('/family/availability/' . $this->mia, $this->week([0 => [['17:00', '19:00']]]))->getStatusCode());
        self::assertSame(0, $this->slotCount('player_profile', $this->mia));
    }

    #[Group('AC-61')]
    public function testOtherRolesAreRefused(): void
    {
        $this->signInAs('trainer');
        self::assertSame(403, $this->get('/family/availability/' . $this->mia)->getStatusCode());
        self::assertSame(403, $this->post('/family/availability/' . $this->mia, $this->week([]))->getStatusCode());
    }

    #[Group('AC-61')]
    public function testAnUnknownProfileIsNotFoundAndASignedOutVisitorIsSentToLogin(): void
    {
        $this->cookies = [];
        $this->login('parent@example.com');
        self::assertSame(404, $this->get('/family/availability/00000000-0000-7000-8000-000000000000')->getStatusCode());
        self::assertSame(404, $this->get('/family/availability/not-an-id')->getStatusCode());

        $this->cookies = [];
        self::assertSame(302, $this->get('/family/availability/' . $this->mia)->getStatusCode());
    }

    #[Group('AC-61')]
    public function testTheFormIsProtectedByCsrf(): void
    {
        $this->cookies = [];
        $this->login('parent@example.com');

        $response = $this->post('/family/availability/' . $this->mia, [...$this->week([0 => [['17:00', '19:00']]]), '_csrf' => 'forged']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->slotCount('player_profile', $this->mia));
    }
}
