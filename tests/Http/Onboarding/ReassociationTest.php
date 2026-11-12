<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use PHPUnit\Framework\Attributes\Group;

/** Edge: a player opens a Trainer link after a parent removed the association. */
final class ReassociationTest extends OnboardingHttpTestCase
{
    /** @return array{string, string, string, string} organization, code, parent, child */
    private function removedChild(): array
    {
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $parent = $this->signInAs('player');
        $mia = $this->childOf($parent);
        $this->associate($mia, $org);
        $this->post('/family/children/' . $mia . '/trainers/' . $org . '/remove');
        self::assertSame(0, $this->activeAssociations($mia), 'precondition: the parent removed the child');

        return [$org, $code, $parent, $mia];
    }

    #[Group('AC-22'), Group('AC-26')]
    public function testARemovedChildIsNeverPreTickedAndNotReAddedWithoutTheParentsChoice(): void
    {
        [, $code, , $mia] = $this->removedChild();

        $body = $this->body($this->get('/join/' . $code));
        $this->post('/join/' . $code . '/who', []);

        self::assertStringContainsString('value="' . $mia . '"', $body);
        self::assertStringNotContainsString('checked', $body);
        self::assertSame(0, $this->activeAssociations($mia));
    }

    #[Group('AC-22'), Group('AC-26')]
    public function testTickingTheRemovedChildCreatesANewAssociationAndKeepsTheHistory(): void
    {
        [$org, $code, , $mia] = $this->removedChild();

        $this->post('/join/' . $code . '/who', ['profile_ids' => [$mia]]);

        self::assertSame(1, $this->activeAssociations($mia));
        self::assertSame(2, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND organization_id = :o', ['p' => $mia, 'o' => $org]));
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE player_profile_id = :p AND ended_at IS NOT NULL', ['p' => $mia]));
    }

    #[Group('AC-21')]
    public function testTheChildLoginOpeningTheLinkAfterRemovalStillAssociatesNobody(): void
    {
        [, $code, $parent, $mia] = $this->removedChild();
        $this->signInAsChildLogin($parent, $mia);

        $this->get('/join/' . $code);

        self::assertSame(0, $this->activeAssociations($mia));
    }
}
