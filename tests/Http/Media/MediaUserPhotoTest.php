<?php

declare(strict_types=1);

namespace Tests\Http\Media;

use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Profile\ProfileHttpTestCase;

/** Who may fetch an account photo (AC-71, design C11): the person, their guardian, associated staff, an administrator. */
#[Group('AC-71')]
final class MediaUserPhotoTest extends ProfileHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $parent;
    private string $profile;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Org A');
        [, $this->orgB] = $this->trainerWithOrganization('Org B');
        $this->parent = $this->signInAs('player');
        $this->profile = $this->childOf($this->parent);
        $this->associate($this->profile, $this->orgA);
        $this->saveProfile(['name' => 'Parent With Photo'], ['photo' => $this->photoUpload()]);
    }

    private function thumb(): int
    {
        return $this->get('/media/user-thumb/' . $this->parent)->getStatusCode();
    }

    public function testTheOwnerSeesTheirPhotoAndAThumbnail(): void
    {
        self::assertSame(200, $this->thumb());
        self::assertSame(200, $this->get('/media/user-photo/' . $this->parent)->getStatusCode());
    }

    public function testTheTrainerAndCoachOfAnAssociatedOrganizationSeeIt(): void
    {
        $this->signInAsCoachOf($this->orgA);
        self::assertSame(200, $this->thumb(), 'Coach');

        $email = (string) $this->scalar('SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o', ['o' => $this->orgA]);
        $this->cookies = [];
        $this->login($email);
        self::assertSame(200, $this->thumb(), 'Trainer');
    }

    public function testOtherTrainersCoachesAndPlayersDoNot(): void
    {
        $emailB = (string) $this->scalar('SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o', ['o' => $this->orgB]);
        $this->cookies = [];
        $this->login($emailB);
        self::assertSame(404, $this->thumb(), 'Trainer of another organization');

        $this->signInAsCoachOf($this->orgB);
        self::assertSame(404, $this->thumb(), 'Coach of another organization');

        $this->signInAs('player');
        self::assertSame(404, $this->thumb(), 'another parent');
    }

    public function testATrainerLosesAccessWhenTheAssociationEnds(): void
    {
        $this->pdo->prepare('UPDATE player_trainer_associations SET ended_at = now() WHERE player_profile_id = :p')->execute(['p' => $this->profile]);
        $email = (string) $this->scalar('SELECT u.email FROM users u JOIN organizations o ON o.owner_user_id = u.id WHERE o.id = :o', ['o' => $this->orgA]);
        $this->cookies = [];
        $this->login($email);

        self::assertSame(404, $this->thumb());
    }

    public function testTheGuardianSeesAChildLoginsPhotoAndAdministratorsSeeAny(): void
    {
        $kid = $this->signInAsChildLogin($this->parent, $this->profile, 'kid2@example.com');
        $this->pdo->prepare('INSERT INTO user_profiles (user_id, photo_path) VALUES (:u, :p)')->execute(['u' => $kid, 'p' => (string) $this->photoPathOf($this->parent)]);

        $parentEmail = (string) $this->user($this->parent)['email'];
        $this->cookies = [];
        $this->login($parentEmail);
        self::assertSame(200, $this->get('/media/user-thumb/' . $kid)->getStatusCode(), 'guardian');

        $this->signInAs('super_admin');
        self::assertSame(200, $this->get('/media/user-thumb/' . $kid)->getStatusCode(), 'administrator');
    }

    public function testAnAccountWithoutAPhotoIsNotFound(): void
    {
        $other = $this->signInAs('coach');

        self::assertSame(404, $this->get('/media/user-thumb/' . $other)->getStatusCode());
    }
}
