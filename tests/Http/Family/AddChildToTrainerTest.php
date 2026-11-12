<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

final class AddChildToTrainerTest extends FamilyHttpTestCase
{
    #[Group('AC-34')]
    public function testAParentAddsAChildByEnteringAShareLinkCode(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        [$linkId, $code] = $this->shareLink($org);

        $response = $this->post('/family/children/' . $child . '/trainers/code', ['code' => ' ' . $code . ' ']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/family/children?notice=added', $this->location($response));
        $row = $this->row('SELECT organization_id, source_share_link_id FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NULL', ['p' => $child]);
        self::assertSame($org, $row['organization_id'] ?? null);
        self::assertSame($linkId, $row['source_share_link_id'] ?? null);
        self::assertStringContainsString('Ace Tennis Academy', $this->body($this->get('/family/children')));
    }

    #[Group('AC-34')]
    public function testAParentAddsAChildToATrainerTheFamilyAlreadyTrainsWith(): void
    {
        $parent = $this->signInAs('player');
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        $this->associate($this->childOf($parent, 'Old', 'Sibling', '2018-01-01'), $org);
        $mia = $this->childOf($parent);

        $page = $this->get('/family/children');
        self::assertStringContainsString('action="/family/children/' . $mia . '/trainers/add"', $this->body($page));

        $response = $this->post('/family/children/' . $mia . '/trainers/add', ['organization_id' => $org]);

        self::assertSame('/family/children?notice=added', $this->location($response));
        self::assertSame(1, $this->activeAssociations($mia));
    }

    #[Group('AC-34')]
    public function testTheChildThenSeesThatTrainersContext(): void
    {
        $parent = $this->signInAs('player');
        $mia = $this->childOf($parent);
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $code] = $this->shareLink($org);
        $this->post('/family/children/' . $mia . '/trainers/code', ['code' => $code]);

        $roster = $this->container->get(\App\Family\Application\TrainerRoster::class);
        self::assertInstanceOf(\App\Family\Application\TrainerRoster::class, $roster);
        $names = array_map(static fn($p): string => $p->fullName(), $roster->forOrganization(\App\Shared\Domain\OrganizationId::fromString($org)));

        self::assertSame(['Mia Smith'], $names);
    }

    #[Group('AC-34')]
    #[Group('AC-26')]
    public function testAnInvalidCodeGivesOneGenericMessageAndAssociatesNobody(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        [, $inactiveOrg] = $this->trainerWithOrganization('Closed Club', 'inactive');
        [, $deactivated] = $this->shareLink($inactiveOrg);
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $oldCode] = $this->shareLink($org, null, false);

        foreach (['x', str_repeat('a', 22), $oldCode, $deactivated] as $code) {
            $response = $this->post('/family/children/' . $child . '/trainers/code', ['code' => $code]);
            self::assertSame(422, $response->getStatusCode(), $code);
            self::assertStringContainsString('This link is not valid.', $this->body($response));
        }
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM share_link_uses'));
    }

    #[Group('AC-34')]
    public function testAddingAnAlreadyAssociatedTrainerChangesNothing(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $this->post('/family/children/' . $child . '/trainers/code', ['code' => $code]);

        $again = $this->post('/family/children/' . $child . '/trainers/code', ['code' => $code]);

        self::assertSame('/family/children?notice=already', $this->location($again));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
        self::assertSame(1, (int) $this->scalar('SELECT use_count FROM share_links'));
    }

    #[Group('AC-34')]
    #[Group('AC-9')]
    public function testAnotherParentsChildCannotBeAddedToATrainer(): void
    {
        $owner = $this->createUser('player', 'owner@example.com');
        $child = $this->childOf($owner);
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $this->signInAs('player');

        self::assertSame(404, $this->post('/family/children/' . $child . '/trainers/code', ['code' => $code])->getStatusCode());
        self::assertSame(404, $this->post('/family/children/' . $child . '/trainers/add', ['organization_id' => $org])->getStatusCode());
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations'));
    }

    #[Group('AC-34')]
    public function testAParentCannotPickATrainerTheFamilyDoesNotKnow(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        [, $stranger] = $this->trainerWithOrganization('Stranger Club');

        $response = $this->post('/family/children/' . $child . '/trainers/add', ['organization_id' => $stranger]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->activeAssociations($child));
    }
}
