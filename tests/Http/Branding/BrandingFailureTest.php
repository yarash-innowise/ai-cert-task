<?php

declare(strict_types=1);

namespace Tests\Http\Branding;

use App\Organization\Domain\Branding;
use App\Organization\Domain\OrganizationBrandings;
use App\Shared\Domain\HexColor;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Storage\FileStorage;
use App\Shared\Infrastructure\Storage\StorageFailed;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ImageFixtures;

/** A branding change that fails halfway leaves the previous logo and color untouched and no stray file behind. */
#[Group('AC-81'), Group('AC-84')]
final class BrandingFailureTest extends BrandingHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAsTrainer();
        $this->saveBranding(['brand_color' => '#AA3300'], ['logo' => $this->logoUpload()]);
    }

    /** @return array{array{logo_path: string|null, brand_color: string|null}, int, int} */
    private function state(): array
    {
        return [$this->brandingRow(), $this->storedFiles('logos'), (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'storage.delete'")];
    }

    public function testACorruptedLogoKeepsThePreviousBranding(): void
    {
        $before = $this->state();

        $response = $this->saveBranding(['brand_color' => '#0000CC'], ['logo' => ImageFixtures::file(substr(ImageFixtures::png(), 0, 30))]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('damaged', $this->body($response));
        self::assertSame($before, $this->state(), 'previous logo, color, files and queue unchanged (the new color is not applied either)');
    }

    public function testAWrongTypeOrAnInvalidColorKeepsThePreviousBranding(): void
    {
        $before = $this->state();

        $this->saveBranding([], ['logo' => ImageFixtures::file('<?php echo 1;', 'logo.png')]);
        $this->saveBranding(['brand_color' => 'blue']);

        self::assertSame($before, $this->state());
        self::assertStringContainsString('--brand-primary: #AA3300', $this->body($this->get('/trainer')));
    }

    public function testAFailedLogoUploadAtTheServerKeepsThePreviousBranding(): void
    {
        $before = $this->state();

        $response = $this->saveBranding([], ['logo' => ImageFixtures::file('', 'logo.png', UPLOAD_ERR_PARTIAL)]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('did not finish', $this->body($response));
        self::assertSame($before, $this->state());
    }

    public function testWhenTheStorageFailsThePreviousBrandingRemainsAndAnErrorIsShown(): void
    {
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
        $before = $this->state();

        $response = $this->saveBranding(['brand_color' => '#0000CC'], ['logo' => $this->logoUpload(ImageFixtures::png(50, 50))]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('previous logo is unchanged', $this->body($response));
        self::assertSame($before, $this->state());
    }

    public function testWhenTheDatabaseUpdateFailsTheNewFileIsRemovedAndNothingIsChanged(): void
    {
        $real = $this->container->get(OrganizationBrandings::class);
        self::assertInstanceOf(OrganizationBrandings::class, $real);
        $this->withServices([OrganizationBrandings::class => new class ($real) implements OrganizationBrandings {
            public function __construct(private readonly OrganizationBrandings $inner) {}

            public function find(OrganizationId $organization): ?Branding
            {
                return $this->inner->find($organization);
            }

            public function lock(OrganizationId $organization): ?Branding
            {
                return $this->inner->lock($organization);
            }

            public function save(OrganizationId $organization, ?string $logoPath, ?HexColor $color): void
            {
                throw new \RuntimeException('database went away');
            }
        }]);
        $before = $this->state();

        $response = $this->saveBranding(['brand_color' => '#0000CC'], ['logo' => $this->logoUpload(ImageFixtures::png(50, 50))]);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame($before, $this->state(), 'the just-stored file was deleted, the old one is kept, no deletion was queued');
    }

    public function testAnExpiredPreviewKeepsThePreviousBranding(): void
    {
        $before = $this->state();

        $response = $this->saveBranding(['staged_logo' => str_repeat('c', 32) . '.png']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->state());
    }
}
