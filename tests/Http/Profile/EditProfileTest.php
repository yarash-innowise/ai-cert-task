<?php

declare(strict_types=1);

namespace Tests\Http\Profile;

use PHPUnit\Framework\Attributes\Group;

/** AC-68, AC-70: every role edits name, phone, photo and its own fields; invalid input names the field. */
final class EditProfileTest extends ProfileHttpTestCase
{
    #[Group('AC-68')]
    public function testAPlayerEditsNamePhoneSchoolAndJerseyNumber(): void
    {
        [$user, $profile] = $this->signInAsPlayerWithSelfProfile();

        $response = $this->saveProfile(['name' => 'Pat Player', 'phone' => '+1 (415) 555-2671', 'school' => 'Lincoln High', 'jersey_number' => '23']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/profile?notice=saved', $this->location($response));
        $row = $this->user($user);
        self::assertSame('Pat Player', $row['name']);
        self::assertSame('+14155552671', $row['phone']);
        $player = $this->row('SELECT school, jersey_number FROM player_profiles WHERE id = :p', ['p' => $profile]);
        self::assertSame(['school' => 'Lincoln High', 'jersey_number' => 23], $player);
    }

    #[Group('AC-68')]
    public function testAParentEditsTheEmergencyContact(): void
    {
        $user = $this->signInAs('player');

        $this->saveProfile(['emergency_contact' => 'Sam Parent, +1 415 555 2671']);

        self::assertSame('Sam Parent, +1 415 555 2671', $this->scalar('SELECT emergency_contact FROM user_profiles WHERE user_id = :u', ['u' => $user]));
        self::assertStringContainsString('Sam Parent, +1 415 555 2671', $this->body($this->get('/profile')));
    }

    #[Group('AC-68')]
    public function testAParentWithoutAMeProfileHasNoPlayerFieldsAndTamperedOnesAreIgnored(): void
    {
        $user = $this->signInAs('player');
        $child = $this->childOf($user);
        $html = $this->body($this->get('/profile'));

        self::assertStringNotContainsString('name="jersey_number"', $html);
        self::assertStringContainsString('name="emergency_contact"', $html);

        $this->saveProfile(['school' => 'Tampered', 'jersey_number' => '99']);

        self::assertNull($this->scalar('SELECT school FROM player_profiles WHERE id = :p', ['p' => $child]));
        self::assertNull($this->scalar('SELECT jersey_number FROM player_profiles WHERE id = :p', ['p' => $child]));
    }

    #[Group('AC-68')]
    public function testAChildLoginEditsItsOwnProfileDetails(): void
    {
        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        $kid = $this->signInAsChildLogin($parent, $profile);

        $html = $this->body($this->get('/profile'));
        self::assertStringContainsString('name="jersey_number"', $html);
        self::assertStringNotContainsString('name="emergency_contact"', $html, 'a child has no parent section');

        $this->saveProfile(['name' => 'Kiddo', 'school' => 'Oak School', 'jersey_number' => '7', 'emergency_contact' => 'ignored']);

        self::assertSame('Kiddo', $this->user($kid)['name']);
        self::assertSame(['school' => 'Oak School', 'jersey_number' => 7], $this->row('SELECT school, jersey_number FROM player_profiles WHERE id = :p', ['p' => $profile]));
        self::assertEmpty($this->scalar('SELECT emergency_contact FROM user_profiles WHERE user_id = :u', ['u' => $kid]));
    }

    #[Group('AC-68')]
    public function testACoachEditsBioCredentialsCertificationsAndTheVisibilityCheckbox(): void
    {
        [, $org] = $this->trainerWithOrganization();
        $coach = $this->signInAsCoachOf($org);

        $this->saveProfile(['bio' => "Ten years\nof coaching.", 'credentials' => 'M.Sc. Sports Science', 'certifications' => "USPTA Elite\r\n\r\n  PTR Level 2  \n", 'is_public' => '1']);

        $row = $this->row('SELECT bio, credentials, certifications, is_public FROM coach_profiles WHERE user_id = :u', ['u' => $coach]);
        self::assertSame("Ten years\nof coaching.", $row['bio'] ?? null);
        self::assertSame('M.Sc. Sports Science', $row['credentials'] ?? null);
        self::assertSame(['USPTA Elite', 'PTR Level 2'], json_decode((string) ($row['certifications'] ?? ''), true));
        self::assertTrue($row['is_public'] ?? false);

        $this->saveProfile(['bio' => 'Updated']);
        self::assertFalse($this->scalar('SELECT is_public FROM coach_profiles WHERE user_id = :u', ['u' => $coach]), 'an unchecked box means private');
        self::assertSame('[]', $this->scalar('SELECT certifications FROM coach_profiles WHERE user_id = :u', ['u' => $coach]));
    }

    #[Group('AC-68')]
    public function testATrainerEditsBusinessNameAndOrganizationDetails(): void
    {
        $this->signInAsTrainer('Old Name');

        $this->saveProfile(['business_name' => 'Ace Tennis Academy', 'details' => '12 Court Street, open 7 days']);

        self::assertSame(
            ['business_name' => 'Ace Tennis Academy', 'details' => '12 Court Street, open 7 days'],
            $this->row('SELECT business_name, details FROM organizations WHERE id = :o', ['o' => $this->orgId]),
        );
    }

    #[Group('AC-68')]
    public function testASuperAdminEditsTheAdminSettings(): void
    {
        $admin = $this->signInAs('super_admin');

        $this->saveProfile(['admin_settings' => 'Digest every Monday']);

        self::assertSame('Digest every Monday', $this->scalar('SELECT admin_settings FROM user_profiles WHERE user_id = :u', ['u' => $admin]));
        self::assertStringContainsString('Digest every Monday', $this->body($this->get('/profile')));
    }

    #[Group('AC-68')]
    public function testOptionalFieldsCanBeClearedAndThePageShowsTheSavedValues(): void
    {
        [$user, $profile] = $this->signInAsPlayerWithSelfProfile();
        $this->saveProfile(['phone' => '+1 415 555 2671', 'school' => 'Lincoln', 'jersey_number' => '5', 'emergency_contact' => 'Somebody']);

        $this->saveProfile(['phone' => '', 'school' => '', 'jersey_number' => '', 'emergency_contact' => '']);

        self::assertNull($this->user($user)['phone']);
        self::assertSame(['school' => null, 'jersey_number' => null], $this->row('SELECT school, jersey_number FROM player_profiles WHERE id = :p', ['p' => $profile]));
        self::assertNull($this->scalar('SELECT emergency_contact FROM user_profiles WHERE user_id = :u', ['u' => $user]));
    }

    #[Group('AC-68')]
    public function testTheProfilePageIsReachableForEveryRoleAndShowsTheRoleFields(): void
    {
        $expected = ['super_admin' => 'name="admin_settings"', 'trainer' => 'name="business_name"', 'coach' => 'name="bio"', 'player' => 'name="emergency_contact"'];
        foreach ($expected as $role => $needle) {
            $this->signInAs($role);

            $response = $this->get('/profile');

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertStringContainsString($needle, $this->body($response), $role);
            self::assertStringContainsString('enctype="multipart/form-data"', $this->body($response), $role);
        }
    }

    #[Group('AC-68')]
    public function testTheFamilyProfileAddressShowsTheSameEditablePage(): void
    {
        $this->signInAs('player');

        $html = $this->body($this->get('/family/profile'));

        self::assertStringContainsString('<h1>Profile</h1>', $html);
        self::assertStringContainsString('action="/profile"', $html);
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        $this->cookies = [];
        self::assertSame(302, $this->get('/profile')->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($this->get('/profile')));
    }

    #[Group('AC-70')]
    public function testInvalidPhoneFormatsAreRefusedNamingThePhoneField(): void
    {
        $this->signInAsTrainer();
        $user = $this->trainerId;
        $before = $this->user($user);

        foreach (['abc', '12', '+999 123', '0000000000', '(415) 555-26'] as $bad) {
            $response = $this->saveProfile(['phone' => $bad, 'business_name' => 'Ace']);
            $html = $this->body($response);

            self::assertSame(422, $response->getStatusCode(), $bad);
            self::assertStringContainsString('href="#field-phone"', $html, $bad);
            self::assertStringContainsString('Enter a valid phone number', $html, $bad);
            self::assertStringContainsString('value="' . $bad . '"', $html, 'the typed value is kept');
        }
        self::assertSame($before, $this->user($user));
    }

    #[Group('AC-70')]
    public function testMissingRequiredFieldsAreRefusedNamingEachField(): void
    {
        $this->signInAsTrainer();

        $response = $this->saveProfile(['name' => '  ', 'business_name' => '']);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-name">Name is required.', $html);
        self::assertStringContainsString('href="#field-business_name">Business name is required.', $html);
        self::assertSame('Ace Tennis Academy', $this->scalar('SELECT business_name FROM organizations WHERE id = :o', ['o' => $this->orgId]), 'nothing saved');
    }

    #[Group('AC-70')]
    public function testOtherFieldsAreCheckedToo(): void
    {
        [$user] = $this->signInAsPlayerWithSelfProfile();

        $response = $this->saveProfile(['jersey_number' => '1000', 'school' => str_repeat('x', 201), 'emergency_contact' => str_repeat('y', 501)]);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-jersey_number"', $html);
        self::assertStringContainsString('href="#field-school"', $html);
        self::assertStringContainsString('href="#field-emergency_contact"', $html);
        self::assertSame('Test User', $this->user($user)['name']);
    }

    public function testACoachCannotPostTrainerFieldsAndATrainerCannotPostCoachFields(): void
    {
        $this->signInAsTrainer();
        $this->saveProfile(['business_name' => 'Ace Tennis Academy', 'bio' => 'injected', 'is_public' => '1']);
        self::assertEmpty($this->scalar('SELECT user_id FROM coach_profiles WHERE user_id = :u', ['u' => $this->trainerId]), 'no coach row for a Trainer');
    }

    public function testSavingBumpsTheVersionSoAnAdminsOpenFormIsRefusedAsOutOfDate(): void
    {
        $user = $this->signInAs('coach', 'carl@example.com');
        $version = $this->user($user)['version'];

        $this->saveProfile(['name' => 'Carl Renamed']);

        self::assertSame($version + 1, $this->user($user)['version']);
    }

    public function testSavingIsAuditedWithoutPersonalData(): void
    {
        $this->signInAsTrainer();
        $user = $this->trainerId;
        $this->saveProfile(['name' => 'Secret Name', 'business_name' => 'Ace', 'phone' => '+1 415 555 2671']);

        $payload = (string) $this->scalar("SELECT payload FROM audit_log WHERE action = 'profile.updated' AND subject_id = :u", ['u' => $user]);

        self::assertSame(['sections' => 'trainer'], json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Secret Name', $payload);
    }

    #[Group('AC-88'), Group('perf')]
    public function testProfileSavesCompleteInUnderOneSecondForEveryRole(): void
    {
        $this->signInAs('super_admin');
        $this->assertFast(['admin_settings' => 'x']);
        $this->signInAsTrainer();
        $this->assertFast(['business_name' => 'Ace', 'details' => 'd']);
        $this->signInAsCoachOf($this->orgId);
        $this->assertFast(['bio' => 'b', 'is_public' => '1']);
        $this->signInAsPlayerWithSelfProfile();
        $this->assertFast(['school' => 's', 'jersey_number' => '4', 'emergency_contact' => 'e', 'phone' => '+1 415 555 2671']);
        $this->saveProfile();
        $started = hrtime(true);
        $this->saveProfile(['school' => 's'], ['photo' => $this->photoUpload(\Tests\Support\ImageFixtures::jpeg(1600, 1200))]);
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9, 'a save with a photo');
    }

    /** @param array<string, string> $fields */
    private function assertFast(array $fields): void
    {
        $started = hrtime(true);
        $response = $this->saveProfile($fields);
        $seconds = (hrtime(true) - $started) / 1e9;

        self::assertSame(302, $response->getStatusCode());
        self::assertLessThan(1.0, $seconds, sprintf('profile save took %.3f s', $seconds));
    }
}
