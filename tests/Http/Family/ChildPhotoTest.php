<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use App\Shared\Infrastructure\Storage\FileStorage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ImageFixtures;

/** AC-27 (optional photo) and AC-71 (limits, thumbnail) on the add-child and edit-child pages. */
final class ChildPhotoTest extends FamilyHttpTestCase
{
    private string $parent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = $this->signInAs('player');
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     */
    private function addWithPhoto(array $fields = [], array $files = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->postFiles('/family/children/new', ['first_name' => 'Mia', 'last_name' => 'Smith', 'birth_date' => '2022-05-01', 'gender' => 'female', ...$fields], $files);
    }

    private function photoOfChild(string $profileId): ?string
    {
        $value = $this->scalar('SELECT photo_path FROM player_profiles WHERE id = :p', ['p' => $profileId]);

        return is_string($value) ? $value : null;
    }

    #[Group('AC-27'), Group('AC-71')]
    public function testAChildIsCreatedWithAnOptionalPhotoAndItsThumbnailIsShown(): void
    {
        $response = $this->addWithPhoto([], ['photo' => ImageFixtures::file(ImageFixtures::png(400, 400))]);

        self::assertSame(302, $response->getStatusCode());
        $profile = (string) $this->scalar("SELECT id FROM player_profiles WHERE owner_user_id = :o AND kind = 'child'", ['o' => $this->parent]);
        self::assertNotNull($this->photoOfChild($profile));
        self::assertSame(2, $this->storedFiles('photos'));
        self::assertStringContainsString('src="/media/profile-thumb/' . $profile . '"', $this->body($this->get('/family/children')));
        self::assertSame(200, $this->get('/media/profile-thumb/' . $profile)->getStatusCode());
    }

    #[Group('AC-27')]
    public function testAChildWithoutAPhotoIsStillCreatedAndTheFormOffersTheUpload(): void
    {
        self::assertStringContainsString('enctype="multipart/form-data"', $this->body($this->get('/family/children/new')));

        $response = $this->addWithPhoto([], ['photo' => ImageFixtures::file('', 'x', UPLOAD_ERR_NO_FILE)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->childCount($this->parent));
        self::assertSame(0, $this->storedFiles('photos'));
    }

    #[Group('AC-71')]
    public function testARefusedPhotoCreatesNoChildAndNamesThePhotoField(): void
    {
        $response = $this->addWithPhoto([], ['photo' => ImageFixtures::file('GIF89a' . str_repeat("\0", 30))]);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-photo"', $html);
        self::assertStringContainsString('value="Mia"', $html, 'the typed values are kept');
        self::assertSame(0, $this->childCount($this->parent));
        self::assertSame(0, $this->storedFiles('photos'));
    }

    #[Group('AC-71')]
    public function testAnOversizePhotoIsRefusedAndAnExactTwoMegabytesIsAccepted(): void
    {
        $over = $this->addWithPhoto([], ['photo' => ImageFixtures::file(ImageFixtures::pngOfSize(2 * 1024 * 1024 + 1))]);
        self::assertSame(422, $over->getStatusCode());
        self::assertSame(0, $this->childCount($this->parent));

        $exact = $this->addWithPhoto([], ['photo' => ImageFixtures::file(ImageFixtures::pngOfSize(2 * 1024 * 1024))]);
        self::assertSame(302, $exact->getStatusCode());
        self::assertSame(1, $this->childCount($this->parent));
    }

    #[Group('AC-27')]
    public function testTheParentEditsAChildsNameSchoolJerseyAndPhoto(): void
    {
        $profile = $this->childOf($this->parent);
        self::assertStringContainsString('value="Mia"', $this->body($this->get('/family/children/' . $profile . '/edit')));

        $response = $this->postFiles('/family/children/' . $profile . '/edit', ['first_name' => 'Maya', 'last_name' => 'Smyth', 'school' => 'Oak School', 'jersey_number' => '12'], ['photo' => ImageFixtures::file(ImageFixtures::jpeg(300, 300))]);

        self::assertSame('/family/children?notice=child_updated', $this->location($response));
        self::assertSame(
            ['first_name' => 'Maya', 'last_name' => 'Smyth', 'school' => 'Oak School', 'jersey_number' => 12],
            $this->row('SELECT first_name, last_name, school, jersey_number FROM player_profiles WHERE id = :p', ['p' => $profile]),
        );
        self::assertNotNull($this->photoOfChild($profile));
        self::assertSame('child', $this->scalar('SELECT kind FROM player_profiles WHERE id = :p', ['p' => $profile]));
        self::assertSame($this->parent, $this->scalar('SELECT owner_user_id FROM player_profiles WHERE id = :p', ['p' => $profile]), 'still linked to the parent');
    }

    #[Group('AC-71')]
    public function testAFailedPhotoOnEditKeepsTheTextAndTheOldPhoto(): void
    {
        $profile = $this->childOf($this->parent);
        $this->postFiles('/family/children/' . $profile . '/edit', ['first_name' => 'Mia', 'last_name' => 'Smith'], ['photo' => ImageFixtures::file(ImageFixtures::png())]);
        $old = $this->photoOfChild($profile);

        $response = $this->postFiles('/family/children/' . $profile . '/edit', ['first_name' => 'Renamed', 'last_name' => 'Smith', 'school' => 'Kept School'], ['photo' => ImageFixtures::file('not an image')]);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Your other changes were saved. The photo was not changed.', $html);
        self::assertSame('Renamed', $this->scalar('SELECT first_name FROM player_profiles WHERE id = :p', ['p' => $profile]));
        self::assertSame('Kept School', $this->scalar('SELECT school FROM player_profiles WHERE id = :p', ['p' => $profile]));
        self::assertSame($old, $this->photoOfChild($profile));
        self::assertSame(2, $this->storedFiles('photos'));
        self::assertTrue($this->storage->exists(FileStorage::PHOTOS, (string) $old));
    }

    public function testRequiredFieldsAreCheckedOnEdit(): void
    {
        $profile = $this->childOf($this->parent);

        $response = $this->post('/family/children/' . $profile . '/edit', ['first_name' => '', 'last_name' => '', 'jersey_number' => 'abc']);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-first_name">First name is required.', $html);
        self::assertStringContainsString('href="#field-last_name">Last name is required.', $html);
        self::assertStringContainsString('href="#field-jersey_number"', $html);
        self::assertSame('Mia', $this->scalar('SELECT first_name FROM player_profiles WHERE id = :p', ['p' => $profile]));
    }

    #[Group('AC-10')]
    public function testAForeignChildAnswersNotFoundAndBirthDateAndGenderCannotBeTamperedWith(): void
    {
        $other = $this->createUser('player', 'otherparent@example.com');
        $foreign = $this->childOf($other, 'Zed', 'Other');
        self::assertSame(404, $this->get('/family/children/' . $foreign . '/edit')->getStatusCode());
        self::assertSame(404, $this->post('/family/children/' . $foreign . '/edit', ['first_name' => 'Hacked', 'last_name' => 'X'])->getStatusCode());
        self::assertSame('Zed', $this->scalar('SELECT first_name FROM player_profiles WHERE id = :p', ['p' => $foreign]));

        $own = $this->childOf($this->parent);
        $this->post('/family/children/' . $own . '/edit', ['first_name' => 'Mia', 'last_name' => 'Smith', 'birth_date' => '1990-01-01', 'gender' => 'male', 'owner_user_id' => $other]);
        self::assertSame(['birth_date' => '2022-05-01', 'gender' => 'female', 'owner_user_id' => $this->parent], $this->row('SELECT birth_date::text AS birth_date, gender, owner_user_id FROM player_profiles WHERE id = :p', ['p' => $own]));
    }

    public function testAChildLoginCannotUseTheParentsEditPage(): void
    {
        $profile = $this->childOf($this->parent);
        $this->signInAsChildLogin($this->parent, $profile);

        self::assertSame(403, $this->get('/family/children/' . $profile . '/edit')->getStatusCode());
        self::assertSame(403, $this->post('/family/children/' . $profile . '/edit', ['first_name' => 'X', 'last_name' => 'Y'])->getStatusCode());
    }
}
