<?php

declare(strict_types=1);

namespace Tests\Http\Profile;

use App\Identity\Domain\UserExtras;
use App\Identity\Domain\UserProfileRepository;
use App\Shared\Infrastructure\Outbox\OutboxWorker;
use App\Shared\Infrastructure\Storage\FileStorage;
use App\Shared\Infrastructure\Storage\ImageStore;
use App\Shared\Infrastructure\Storage\StorageFailed;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ImageFixtures;

/**
 * AC-71 and the edge case "photo upload fails midway": the photo is processed separately from the text. The
 * text save commits, a refused or failed photo shows its own error, and the old photo stays untouched.
 */
final class ProfilePhotoFailureTest extends ProfileHttpTestCase
{
    private string $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->signInAs('player', null, 'Original Name');
    }

    /** @return array{string|null, int} the stored photo name and the number of stored photo files */
    private function photoState(): array
    {
        return [$this->photoPathOf($this->user), $this->storedFiles('photos')];
    }

    #[Group('AC-71')]
    public function testAValidPhotoIsStoredAsAFullCopyAndAThumbnailAndShownAfterSaving(): void
    {
        $response = $this->saveProfile([], ['photo' => $this->photoUpload(ImageFixtures::jpeg(1500, 900))]);

        self::assertSame(302, $response->getStatusCode());
        $name = $this->photoPathOf($this->user);
        self::assertIsString($name);
        self::assertSame(2, $this->storedFiles('photos'));
        $full = getimagesizefromstring((string) $this->storage->get(FileStorage::PHOTOS, $name));
        $thumb = getimagesizefromstring((string) $this->storage->get(FileStorage::PHOTOS, ImageStore::thumbName($name)));
        self::assertIsArray($full);
        self::assertIsArray($thumb);
        self::assertSame([512, 307], [$full[0], $full[1]]);
        self::assertSame([128, 77], [$thumb[0], $thumb[1]]);

        $html = $this->body($this->get('/profile'));
        self::assertStringContainsString('src="/media/user-thumb/' . $this->user . '"', $html);
        $served = $this->get('/media/user-thumb/' . $this->user);
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/jpeg', $served->getHeaderLine('Content-Type'));
    }

    #[Group('AC-71')]
    public function testThePhotoIsStoredUnderAGeneratedNameAndTheOriginalPayloadIsDropped(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload(ImageFixtures::png() . '<?php system("id");', '../../shell.php.png')]);

        $name = (string) $this->photoPathOf($this->user);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $name);
        self::assertStringNotContainsString('<?php', (string) $this->storage->get(FileStorage::PHOTOS, $name));
    }

    #[Group('AC-71')]
    public function testAPhotoOfAnotherTypeIsRefusedWithAMessageNamingThePhotoField(): void
    {
        $response = $this->saveProfile([], ['photo' => $this->photoUpload('GIF89a' . str_repeat("\0", 30), 'x.png')]);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('href="#field-photo"', $html);
        self::assertStringContainsString('This file type is not allowed. Upload a PNG or JPG image.', $html);
        self::assertSame([null, 0], $this->photoState());
    }

    #[Group('AC-71')]
    public function testSvgIsNotAcceptedAsAPhoto(): void
    {
        $response = $this->saveProfile([], ['photo' => $this->photoUpload(ImageFixtures::svg(), 'x.svg')]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([null, 0], $this->photoState());
    }

    #[Group('AC-71')]
    public function testThePhotoSizeLimitIsTwoMegabytesInclusive(): void
    {
        $over = $this->saveProfile([], ['photo' => $this->photoUpload(ImageFixtures::pngOfSize(2 * 1024 * 1024 + 1))]);
        self::assertSame(422, $over->getStatusCode());
        self::assertStringContainsString('larger than 2 MB', $this->body($over));
        self::assertSame([null, 0], $this->photoState());

        $exact = $this->saveProfile([], ['photo' => $this->photoUpload(ImageFixtures::pngOfSize(2 * 1024 * 1024))]);
        self::assertSame(302, $exact->getStatusCode());
        self::assertNotNull($this->photoPathOf($this->user));
    }

    #[Group('AC-71')]
    public function testACorruptedPhotoIsRefused(): void
    {
        $response = $this->saveProfile([], ['photo' => $this->photoUpload(substr(ImageFixtures::jpeg(), 0, 40))]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('damaged', $this->body($response));
    }

    public function testARefusedPhotoKeepsTheTextChangesAndTheOldPhoto(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload()]);
        $before = $this->photoState();

        $response = $this->saveProfile(['name' => 'Brand New Name', 'phone' => '+1 415 555 2671', 'emergency_contact' => 'Kept'], ['photo' => $this->photoUpload('not an image at all')]);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Your other changes were saved. The photo was not changed.', $html);
        self::assertStringContainsString('href="#field-photo"', $html);
        self::assertStringContainsString('value="Brand New Name"', $html, 'the page shows the saved text');
        self::assertSame('Brand New Name', $this->user($this->user)['name']);
        self::assertSame('Kept', $this->scalar('SELECT emergency_contact FROM user_profiles WHERE user_id = :u', ['u' => $this->user]));
        self::assertSame($before, $this->photoState());
    }

    public function testAnUploadThatFailsMidwayKeepsTheTextChangesAndTheOldPhoto(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload()]);
        $before = $this->photoState();

        $response = $this->saveProfile(['name' => 'Saved Anyway'], ['photo' => ImageFixtures::file('', 'me.png', UPLOAD_ERR_PARTIAL)]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('did not finish', $this->body($response));
        self::assertSame('Saved Anyway', $this->user($this->user)['name']);
        self::assertSame($before, $this->photoState());
    }

    public function testNotChoosingAFileLeavesTheExistingPhoto(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload()]);
        $before = $this->photoState();

        $response = $this->saveProfile(['name' => 'Only Text'], ['photo' => ImageFixtures::file('', 'x', UPLOAD_ERR_NO_FILE)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, $this->photoState());
    }

    public function testWhenTheStorageFailsTheTextIsSavedAndTheOldPhotoRemains(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload()]);
        $before = $this->photoState();
        $this->withServices([FileStorage::class => new class ($this->storage) implements FileStorage {
            public function __construct(private readonly FileStorage $inner) {}

            public function put(string $kind, string $contents, string $extension): string
            {
                throw new StorageFailed('disk full');
            }

            public function putAs(string $kind, string $name, string $contents): void
            {
                throw new StorageFailed('disk full');
            }

            public function get(string $kind, string $name): ?string
            {
                return $this->inner->get($kind, $name);
            }

            public function delete(string $kind, string $name): void
            {
                $this->inner->delete($kind, $name);
            }

            public function exists(string $kind, string $name): bool
            {
                return $this->inner->exists($kind, $name);
            }
        }]);

        $response = $this->saveProfile(['name' => 'Text Survives'], ['photo' => $this->photoUpload()]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('previous photo is unchanged', $this->body($response));
        self::assertSame('Text Survives', $this->user($this->user)['name']);
        self::assertSame($before, $this->photoState());
    }

    public function testWhenTheDatabaseFailsAfterStoringTheNewFilesAreRemoved(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload()]);
        $before = $this->photoState();
        $real = $this->container->get(UserProfileRepository::class);
        self::assertInstanceOf(UserProfileRepository::class, $real);
        $this->withServices([UserProfileRepository::class => new class ($real) implements UserProfileRepository {
            public function __construct(private readonly UserProfileRepository $inner) {}

            public function find(string $userId): UserExtras
            {
                return $this->inner->find($userId);
            }

            public function saveText(string $userId, ?string $emergencyContact, ?string $adminSettings): void
            {
                $this->inner->saveText($userId, $emergencyContact, $adminSettings);
            }

            public function swapPhoto(string $userId, ?string $photoPath): ?string
            {
                throw new \RuntimeException('database went away');
            }
        }]);

        $response = $this->saveProfile(['name' => 'Text First'], ['photo' => $this->photoUpload()]);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Text First', $this->user($this->user)['name'], 'the text transaction had already committed');
        self::assertSame($before, $this->photoState(), 'no stray files, old photo kept');
    }

    public function testReplacingAPhotoRemovesTheOldFilesAfterTheCommit(): void
    {
        $this->saveProfile([], ['photo' => $this->photoUpload()]);
        $old = (string) $this->photoPathOf($this->user);

        $this->saveProfile([], ['photo' => $this->photoUpload(ImageFixtures::jpeg(80, 80), 'new.jpg')]);

        $new = (string) $this->photoPathOf($this->user);
        self::assertNotSame($old, $new);
        self::assertSame(4, $this->storedFiles('photos'), 'old files stay until the worker runs');
        $worker = $this->container->get(OutboxWorker::class);
        self::assertInstanceOf(OutboxWorker::class, $worker);
        $worker->runOnce();
        self::assertFalse($this->storage->exists(FileStorage::PHOTOS, $old));
        self::assertFalse($this->storage->exists(FileStorage::PHOTOS, ImageStore::thumbName($old)));
        self::assertTrue($this->storage->exists(FileStorage::PHOTOS, $new));
    }

    public function testAChildLoginsPhotoBelongsToItsChildProfile(): void
    {
        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        $kid = $this->signInAsChildLogin($parent, $profile);

        $this->saveProfile(['name' => 'Kid'], ['photo' => $this->photoUpload()]);

        self::assertNull($this->photoPathOf($kid));
        $path = $this->scalar('SELECT photo_path FROM player_profiles WHERE id = :p', ['p' => $profile]);
        self::assertIsString($path);
        self::assertStringContainsString('/media/profile-thumb/' . $profile, $this->body($this->get('/profile')));
    }
}
