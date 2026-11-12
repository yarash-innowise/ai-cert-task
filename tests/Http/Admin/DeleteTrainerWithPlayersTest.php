<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

/**
 * Edge case (amended 2026-11-12, Q-01.15): only active coaches block deleting a Trainer. Players do not block
 * it; their associations with that Trainer end, their history is kept and they may belong to other Trainers.
 */
final class DeleteTrainerWithPlayersTest extends LifecycleTestCase
{
    #[Group('AC-76'), Group('AC-77')]
    public function testPlayersDoNotBlockDeletingATrainerAndTheirAssociationsEndWithHistoryKept(): void
    {
        [, $otherOrg] = $this->trainerWithOrganization('Beta Tennis');
        $this->associate($this->mia, $otherOrg);
        self::assertSame(2, $this->activeAssociations($this->mia));

        $response = $this->deleteUser($this->trainer);

        self::assertSame(302, $response->getStatusCode(), 'players must not block the deletion');
        self::assertSame('deleted', $this->statusOf($this->trainer));
        $rows = (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND organization_id = :o', ['p' => $this->mia, 'o' => $this->org]);
        self::assertSame(1, $rows, 'the association row is kept as history');
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND organization_id = :o AND ended_at IS NULL', ['p' => $this->mia, 'o' => $this->org]), 'the association with the deleted Trainer has ended');
        self::assertSame(1, $this->activeAssociations($this->mia), 'the association with the other Trainer is untouched');
        self::assertSame($this->mia, (string) $this->scalar('SELECT id FROM player_profiles WHERE id = :p', ['p' => $this->mia]));
    }
}
