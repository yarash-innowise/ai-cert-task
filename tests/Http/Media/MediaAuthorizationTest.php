<?php

declare(strict_types=1);

namespace Tests\Http\Media;

use App\Shared\Infrastructure\Storage\FileStorage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Family\FamilyHttpTestCase;
use Tests\Support\ImageFixtures;

/**
 * Private images are served only to the people the design allows (C11): logos to the members of that
 * organization, profile photos to the owner, the child's own login, the Trainer/Coach of an organization
 * the profile is associated with, and a Super Admin. Everyone else gets 404, the same as a missing file.
 */
final class MediaAuthorizationTest extends FamilyHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $logoName;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Org A');
        [, $this->orgB] = $this->trainerWithOrganization('Org B');
        $this->logoName = $this->storage->put(FileStorage::LOGOS, ImageFixtures::png(), 'png');
        $this->pdo->prepare('UPDATE organizations SET logo_path = :p WHERE id = :o')->execute(['p' => $this->logoName, 'o' => $this->orgA]);
    }

    private function trainerOf(string $organizationId): string
    {
        return (string) $this->scalar('SELECT owner_user_id FROM organizations WHERE id = :o', ['o' => $organizationId]);
    }

    private function signInAsOwnerOf(string $organizationId): void
    {
        $email = (string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $this->trainerOf($organizationId)]);
        $this->cookies = [];
        $this->login($email);
    }

    private function coachOf(string $organizationId): string
    {
        $coach = $this->createUser('coach', 'coach' . bin2hex(random_bytes(3)) . '@example.com');
        $this->pdo->prepare("INSERT INTO coach_memberships (organization_id, coach_user_id, status) VALUES (:o, :c, 'active')")->execute(['o' => $organizationId, 'c' => $coach]);
        $email = (string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $coach]);
        $this->cookies = [];
        $this->login($email);

        return $coach;
    }

    /**
     * A parent with a child associated with the organization (when given), signed in as the parent.
     *
     * @return array{string, string} parent id and profile id
     */
    private function parentWithChild(?string $organizationId): array
    {
        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        if ($organizationId !== null) {
            $this->associate($profile, $organizationId);
        }

        return [$parent, $profile];
    }

    private function setPhoto(string $profileId): string
    {
        $name = $this->storage->put(FileStorage::PHOTOS, ImageFixtures::png(20, 20), 'png');
        $this->storage->putAs(FileStorage::PHOTOS, \App\Shared\Infrastructure\Storage\ImageStore::thumbName($name), ImageFixtures::png(8, 8));
        $this->pdo->prepare('UPDATE player_profiles SET photo_path = :p WHERE id = :i')->execute(['p' => $name, 'i' => $profileId]);

        return $name;
    }

    #[Group('AC-83')]
    public function testTheTrainerOwningTheOrganizationSeesTheLogoWithSafeHeaders(): void
    {
        $this->signInAsOwnerOf($this->orgA);

        $response = $this->get('/media/logo/' . $this->orgA);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertSame(ImageFixtures::png(), $this->body($response));
    }

    #[Group('AC-83')]
    public function testMembersOfTheOrganizationSeeTheLogo(): void
    {
        $this->coachOf($this->orgA);
        self::assertSame(200, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'coach');

        $this->parentWithChild($this->orgA);
        self::assertSame(200, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'parent with an associated child');
    }

    #[Group('AC-83')]
    public function testAnyoneOutsideTheOrganizationGetsNotFound(): void
    {
        $this->signInAsOwnerOf($this->orgB);
        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'another Trainer');

        $this->coachOf($this->orgB);
        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'a Coach of another organization');

        $this->parentWithChild($this->orgB);
        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'a parent of another organization');

        $this->parentWithChild(null);
        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'a parent without any Trainer');
    }

    #[Group('AC-83')]
    public function testALogoIsNoLongerVisibleAfterTheAssociationEnds(): void
    {
        [, $profile] = $this->parentWithChild($this->orgA);
        self::assertSame(200, $this->get('/media/logo/' . $this->orgA)->getStatusCode());

        $this->pdo->prepare('UPDATE player_trainer_associations SET ended_at = now() WHERE player_profile_id = :p')->execute(['p' => $profile]);

        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode());
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        $response = $this->get('/media/logo/' . $this->orgA, cookies: []);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/login', $this->location($response));
    }

    public function testASvgLogoIsServedWithALockedDownPolicy(): void
    {
        $name = $this->storage->put(FileStorage::LOGOS, ImageFixtures::svg(), 'svg');
        $this->pdo->prepare('UPDATE organizations SET logo_path = :p WHERE id = :o')->execute(['p' => $name, 'o' => $this->orgA]);
        $this->signInAsOwnerOf($this->orgA);

        $response = $this->get('/media/logo/' . $this->orgA);

        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testAnUnchangedFileAnswers304ToAConditionalRequest(): void
    {
        $this->signInAsOwnerOf($this->orgA);
        $etag = $this->get('/media/logo/' . $this->orgA)->getHeaderLine('ETag');

        $response = $this->get('/media/logo/' . $this->orgA, ['If-None-Match' => $etag]);

        self::assertNotSame('', $etag);
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $this->body($response));
    }

    public function testUnknownKindsMalformedIdsAndMissingFilesAreNotFound(): void
    {
        $this->signInAsOwnerOf($this->orgA);

        self::assertSame(404, $this->get('/media/nothing/' . $this->orgA)->getStatusCode());
        self::assertSame(404, $this->get('/media/logo/not-a-uuid')->getStatusCode());
        self::assertSame(404, $this->get('/media/logo/00000000-0000-7000-8000-000000000000')->getStatusCode());
        $this->storage->delete(FileStorage::LOGOS, $this->logoName);
        self::assertSame(404, $this->get('/media/logo/' . $this->orgA)->getStatusCode(), 'row without a file');
    }

    #[Group('AC-71')]
    public function testAProfilePhotoAndItsThumbnailAreSeenByTheOwnerAndTheAssociatedTrainerAndCoach(): void
    {
        [, $profile] = $this->parentWithChild($this->orgA);
        $this->setPhoto($profile);

        $full = $this->get('/media/profile-photo/' . $profile);
        $thumb = $this->get('/media/profile-thumb/' . $profile);
        self::assertSame(200, $full->getStatusCode());
        self::assertSame(ImageFixtures::png(20, 20), $this->body($full));
        self::assertSame(ImageFixtures::png(8, 8), $this->body($thumb));

        $this->signInAsOwnerOf($this->orgA);
        self::assertSame(200, $this->get('/media/profile-thumb/' . $profile)->getStatusCode(), 'associated Trainer');
        $this->coachOf($this->orgA);
        self::assertSame(200, $this->get('/media/profile-thumb/' . $profile)->getStatusCode(), 'associated Coach');
    }

    #[Group('AC-71')]
    public function testAChildLoginSeesItsOwnPhotoAndAParentSeesAChildPhoto(): void
    {
        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        $this->setPhoto($profile);
        $this->signInAsChildLogin($parent, $profile);

        self::assertSame(200, $this->get('/media/profile-photo/' . $profile)->getStatusCode());
    }

    #[Group('AC-71')]
    public function testOthersCannotSeeAProfilePhoto(): void
    {
        [, $profile] = $this->parentWithChild($this->orgA);
        $this->setPhoto($profile);

        $this->signInAsOwnerOf($this->orgB);
        self::assertSame(404, $this->get('/media/profile-photo/' . $profile)->getStatusCode(), 'Trainer of another organization');
        $this->coachOf($this->orgB);
        self::assertSame(404, $this->get('/media/profile-photo/' . $profile)->getStatusCode(), 'Coach of another organization');
        $this->signInAs('player');
        self::assertSame(404, $this->get('/media/profile-photo/' . $profile)->getStatusCode(), 'another parent');
    }

    public function testATrainerWhoseAssociationEndedLosesAccessToTheChildPhoto(): void
    {
        [, $profile] = $this->parentWithChild($this->orgA);
        $this->setPhoto($profile);
        $this->pdo->prepare('UPDATE player_trainer_associations SET ended_at = now() WHERE player_profile_id = :p')->execute(['p' => $profile]);

        $this->signInAsOwnerOf($this->orgA);

        self::assertSame(404, $this->get('/media/profile-photo/' . $profile)->getStatusCode());
    }

    public function testASuperAdminMaySeeAProfilePhoto(): void
    {
        [, $profile] = $this->parentWithChild(null);
        $this->setPhoto($profile);

        $this->signInAs('super_admin');

        self::assertSame(200, $this->get('/media/profile-photo/' . $profile)->getStatusCode());
    }
}
