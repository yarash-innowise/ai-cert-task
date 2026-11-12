<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Shared\Infrastructure\Outbox\OutboxWorker;
use App\Shared\Infrastructure\Storage\FileStorage;
use App\Shared\Infrastructure\Storage\ImageStore;
use PHPUnit\Framework\Attributes\Group;

/** What deleting a user removes, table by table (AC-76); nothing personal may stay behind. */
final class AnonymizationTest extends LifecycleTestCase
{
    private string $selfProfile;
    private string $photo;
    private string $logo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare("UPDATE users SET phone = '+14155552671' WHERE id = :i")->execute(['i' => $this->parent]);
        $this->selfProfile = $this->selfOf($this->parent);
        $this->associate($this->selfProfile, $this->org);
        $this->photo = $this->storage->put(FileStorage::PHOTOS, 'full-bytes', 'jpg');
        $this->storage->putAs(FileStorage::PHOTOS, ImageStore::thumbName($this->photo), 'thumb-bytes');
        $this->pdo->prepare("INSERT INTO user_profiles (user_id, photo_path, emergency_contact) VALUES (:u, :p, 'Grandma +1 415 555 0000')")->execute(['u' => $this->parent, 'p' => $this->photo]);
        $this->pdo->prepare("UPDATE player_profiles SET school = 'Lincoln High', jersey_number = 7, photo_path = :p WHERE id = :i")->execute(['p' => $this->photo, 'i' => $this->selfProfile]);
        $this->pdo->prepare("INSERT INTO availability_slots (subject_type, subject_id, weekday, starts_at, ends_at) VALUES ('player_profile', :s, 1, '17:00', '19:00')")->execute(['s' => $this->selfProfile]);
        $this->pdo->prepare("INSERT INTO availability_unavailable_days (subject_type, subject_id, weekday) VALUES ('player_profile', :s, 3)")->execute(['s' => $this->selfProfile]);
        $this->pdo->prepare("INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at) VALUES (:u, 'password_reset', 'hash-1', '2031-01-01 00:00:00+00')")->execute(['u' => $this->parent]);
        $this->pdo->prepare("INSERT INTO login_attempts (email, ip, succeeded) VALUES (:e, '203.0.113.5', false)")->execute(['e' => self::PARENT_EMAIL]);
    }

    private function runWorker(): void
    {
        $worker = $this->container->get(OutboxWorker::class);
        self::assertInstanceOf(OutboxWorker::class, $worker);
        $worker->runOnce();
    }

    #[Group('AC-76')]
    public function testEveryPersonalColumnOfTheAccountAndItsProfilesIsCleared(): void
    {
        $this->deleteUser($this->parent);

        $user = $this->row('SELECT * FROM users WHERE id = :i', ['i' => $this->parent]);
        self::assertIsArray($user);
        self::assertSame('Deleted User', $user['name']);
        self::assertSame('deleted_' . $this->parent . '@example.com', $user['email']);
        foreach (['phone', 'password_hash', 'email_verified_at'] as $column) {
            self::assertNull($user[$column], $column);
        }
        $extras = $this->row('SELECT * FROM user_profiles WHERE user_id = :i', ['i' => $this->parent]);
        self::assertIsArray($extras);
        self::assertNull($extras['photo_path'], 'the photo is the default avatar again');
        self::assertNull($extras['emergency_contact']);
        $profile = $this->row('SELECT * FROM player_profiles WHERE id = :i', ['i' => $this->selfProfile]);
        self::assertIsArray($profile);
        self::assertSame('Deleted', $profile['first_name']);
        self::assertSame('User', $profile['last_name']);
        foreach (['birth_date', 'school', 'jersey_number', 'photo_path'] as $column) {
            self::assertNull($profile[$column], $column);
        }
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM auth_tokens WHERE user_id = :u', ['u' => $this->parent]));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM login_attempts WHERE email = :e', ['e' => self::PARENT_EMAIL]), 'the original email leaves no trace');
    }

    #[Group('AC-76')]
    public function testThePhotoFilesAreRemovedAfterTheWorkerRunsAndTheWeeklyAvailabilityIsErased(): void
    {
        $this->deleteUser($this->parent);

        self::assertTrue($this->storage->exists(FileStorage::PHOTOS, $this->photo), 'files go only after the commit, through the outbox');
        $this->runWorker();

        self::assertFalse($this->storage->exists(FileStorage::PHOTOS, $this->photo));
        self::assertFalse($this->storage->exists(FileStorage::PHOTOS, ImageStore::thumbName($this->photo)));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM availability_slots WHERE subject_id = :s", ['s' => $this->selfProfile]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM availability_unavailable_days WHERE subject_id = :s", ['s' => $this->selfProfile]));
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE status = 'failed'"));
    }

    #[Group('AC-76')]
    public function testACoachsProfileTextAndScheduleAreErased(): void
    {
        $coach = $this->activeCoach();
        $this->deactivate($coach);
        $this->pdo->prepare("INSERT INTO coach_profiles (user_id, bio, credentials, certifications, is_public) VALUES (:u, 'Ex-pro', 'Level 3', '[\"ITF\"]', true)")->execute(['u' => $coach]);
        $this->pdo->prepare("INSERT INTO availability_slots (subject_type, subject_id, weekday, starts_at, ends_at) VALUES ('coach', :s, 2, '09:00', '11:00')")->execute(['s' => $coach]);

        $this->deleteUser($coach);
        $this->runWorker();

        $profile = $this->row('SELECT * FROM coach_profiles WHERE user_id = :u', ['u' => $coach]);
        self::assertIsArray($profile);
        self::assertNull($profile['bio']);
        self::assertNull($profile['credentials']);
        self::assertSame('[]', $profile['certifications']);
        self::assertFalse($profile['is_public']);
        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM availability_slots WHERE subject_id = :s", ['s' => $coach]));
    }

    #[Group('AC-76')]
    public function testATrainersLogoIsRemovedAndTheOrganizationBecomesInactive(): void
    {
        $this->logo = $this->storage->put(FileStorage::LOGOS, 'logo-bytes', 'png');
        $this->pdo->prepare('UPDATE organizations SET logo_path = :l WHERE id = :o')->execute(['l' => $this->logo, 'o' => $this->org]);

        $this->deleteUser($this->trainer);
        $this->runWorker();

        self::assertNull($this->scalar('SELECT logo_path FROM organizations WHERE id = :o', ['o' => $this->org]));
        self::assertFalse($this->storage->exists(FileStorage::LOGOS, $this->logo));
        self::assertSame('Deleted User', $this->scalar('SELECT name FROM users WHERE id = :u', ['u' => $this->trainer]));
        self::assertSame('Ace Tennis Academy', $this->scalar('SELECT business_name FROM organizations WHERE id = :o', ['o' => $this->org]), 'the business is not the person');
    }

    #[Group('AC-76')]
    public function testDeletingAChildLoginAnonymizesItsOwnProfileButNotTheParents(): void
    {
        $this->deleteUser($this->kid);

        self::assertSame('Deleted User', $this->scalar('SELECT name FROM users WHERE id = :u', ['u' => $this->kid]));
        self::assertSame('Deleted', $this->scalar('SELECT first_name FROM player_profiles WHERE id = :p', ['p' => $this->mia]));
        self::assertSame('Pat Parent', $this->scalar('SELECT name FROM users WHERE id = :u', ['u' => $this->parent]));
        self::assertSame('Pat', $this->scalar('SELECT first_name FROM player_profiles WHERE id = :p', ['p' => $this->selfProfile]));
    }
}
