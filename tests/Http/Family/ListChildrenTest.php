<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\Group;

final class ListChildrenTest extends FamilyHttpTestCase
{
    #[Group('AC-33')]
    public function testEveryChildIsListedWithNameAgeAndTrainersWithAssociationDates(): void
    {
        $parent = $this->signInAs('player');
        [, $orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $orgB] = $this->trainerWithOrganization('Baseline Club');
        $mia = $this->childOf($parent, 'Mia', 'Smith', '2022-05-01');
        $this->childOf($parent, 'Sam', 'Smith', '2016-02-29', 'male');
        $this->associate($mia, $orgA, '2030-01-15 09:00:00+00');
        $this->associate($mia, $orgB, '2030-02-20 09:00:00+00');

        $response = $this->get('/family/children');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('Mia Smith', $body);
        self::assertStringContainsString('Age 7', $body);
        self::assertStringContainsString('Sam Smith', $body);
        self::assertStringContainsString('Age 14', $body);
        self::assertStringContainsString('Ace Tennis Academy', $body);
        self::assertStringContainsString('since 2030-01-15', $body);
        self::assertStringContainsString('Baseline Club', $body);
        self::assertStringContainsString('since 2030-02-20', $body);
        self::assertStringContainsString('Not associated with a Trainer yet.', $body);
    }

    #[Group('AC-33')]
    public function testOnlyTheParentsOwnChildrenAreShown(): void
    {
        $other = $this->createUser('player', 'other@example.com');
        $this->childOf($other, 'Stranger', 'Child');
        $parent = $this->signInAs('player');
        $this->childOf($parent, 'Mia', 'Smith');

        $body = $this->body($this->get('/family/children'));

        self::assertStringContainsString('Mia Smith', $body);
        self::assertStringNotContainsString('Stranger', $body);
    }

    #[Group('AC-33')]
    public function testAnEndedAssociationIsNotListed(): void
    {
        $parent = $this->signInAs('player');
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        $child = $this->childOf($parent);
        $this->associate($child, $org);
        $this->pdo->exec("UPDATE player_trainer_associations SET ended_at = now()");

        self::assertStringNotContainsString('Ace Tennis Academy', $this->body($this->get('/family/children')));
    }

    #[Group('AC-32')]
    public function testEachChildAndTrainerPairIsOneDistinctContextForEpicTwo(): void
    {
        $parent = $this->signInAs('player');
        [, $orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $orgB] = $this->trainerWithOrganization('Baseline Club');
        $mia = $this->childOf($parent, 'Mia');
        $sam = $this->childOf($parent, 'Sam', 'Smith', '2016-02-29', 'male');
        foreach ([[$mia, $orgA], [$mia, $orgB], [$sam, $orgA]] as [$p, $o]) {
            $this->associate($p, $o);
        }

        $contexts = $this->scalar('SELECT count(DISTINCT (player_profile_id, organization_id)) FROM player_trainer_associations WHERE ended_at IS NULL');
        self::assertSame(3, (int) $contexts);

        $this->expectException(\PDOException::class);
        $this->associate($mia, $orgA); // the same active pair twice is impossible: one context per child and Trainer
    }
}
