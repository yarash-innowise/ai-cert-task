<?php

declare(strict_types=1);

namespace Tests\Http\Branding;

use App\Shared\Infrastructure\Outbox\OutboxWorker;
use App\Shared\Infrastructure\Storage\FileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ImageFixtures;

final class UpdateBrandingTest extends BrandingHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signInAsTrainer();
    }

    /** @return array<string, array{string, string, string}> */
    public static function acceptedLogos(): array
    {
        return [
            'png' => [ImageFixtures::png(60, 40), 'logo.png', 'png'],
            'jpg' => [ImageFixtures::jpeg(60, 40), 'logo.jpg', 'jpg'],
            'svg' => [ImageFixtures::svg(), 'logo.svg', 'svg'],
        ];
    }

    #[Group('AC-81'), DataProvider('acceptedLogos')]
    public function testAPngJpgOrSvgLogoIsAcceptedStoredAndServed(string $bytes, string $name, string $extension): void
    {
        $response = $this->saveBranding([], ['logo' => ImageFixtures::file($bytes, $name)]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/trainer/branding?notice=saved', $this->location($response));
        $row = $this->brandingRow();
        self::assertIsString($row['logo_path']);
        self::assertStringEndsWith('.' . $extension, $row['logo_path']);
        self::assertTrue($this->storage->exists(FileStorage::LOGOS, $row['logo_path']));
        $served = $this->get('/media/logo/' . $this->orgId);
        self::assertSame(200, $served->getStatusCode());
        self::assertSame($extension === 'svg' ? 'image/svg+xml' : ($extension === 'png' ? 'image/png' : 'image/jpeg'), $served->getHeaderLine('Content-Type'));
    }

    #[Group('AC-81')]
    public function testTheFileIsStoredUnderARandomNameNotTheUploadedOne(): void
    {
        $this->saveBranding([], ['logo' => $this->logoUpload(name: '../../evil name.png')]);

        $name = (string) $this->brandingRow()['logo_path'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $name);
    }

    #[Group('AC-81')]
    public function testTheLogoAppearsInTheHeaderOfTheTrainersPlayersCoachesAndParents(): void
    {
        $this->saveBranding([], ['logo' => $this->logoUpload()]);
        $needle = 'src="/media/logo/' . $this->orgId . '"';

        self::assertStringContainsString($needle, $this->body($this->get('/trainer')), 'the Trainer');

        $this->signInAsParentOf($this->orgId);
        self::assertStringContainsString($needle, $this->body($this->get('/family')), 'a parent');

        $this->signInAsCoachOf($this->orgId);
        self::assertStringContainsString($needle, $this->body($this->get('/coach')), 'a Coach');

        $parent = $this->signInAs('player');
        $profile = $this->childOf($parent);
        $this->associate($profile, $this->orgId);
        $this->signInAsChildLogin($parent, $profile);
        self::assertStringContainsString($needle, $this->body($this->get('/family')), 'a child login');
    }

    #[Group('AC-81')]
    public function testThePreviewShowsTheLogoAndColorBeforeAnythingIsSaved(): void
    {
        $response = $this->postFiles('/trainer/branding', ['action' => 'preview', 'brand_color' => '#AA3300'], ['logo' => $this->logoUpload()]);
        $html = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('This is a preview', $html);
        self::assertStringContainsString('src="data:image/png;base64,', $html);
        self::assertStringContainsString('--brand-primary: #AA3300', $html);
        self::assertSame(['logo_path' => null, 'brand_color' => null], $this->brandingRow(), 'nothing saved');
        self::assertSame(0, $this->storedFiles('logos'));
        self::assertSame(1, $this->storedFiles('tmp'));
        self::assertStringNotContainsString('--brand-primary: #AA3300', $this->body($this->get('/trainer')), 'not applied to the portal');
    }

    #[Group('AC-81')]
    public function testSavingAPreviewedLogoDoesNotNeedTheFileAgain(): void
    {
        $html = $this->body($this->postFiles('/trainer/branding', ['action' => 'preview', 'brand_color' => '#AA3300'], ['logo' => $this->logoUpload()]));
        self::assertSame(1, preg_match('/name="staged_logo" value="([0-9a-f]{32}\.png)"/', $html, $m));

        $response = $this->saveBranding(['brand_color' => '#AA3300', 'staged_logo' => $m[1] ?? ''], ['logo' => ImageFixtures::file('', 'x', UPLOAD_ERR_NO_FILE)]);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->brandingRow();
        self::assertSame('#AA3300', $row['brand_color']);
        self::assertTrue($this->storage->exists(FileStorage::LOGOS, (string) $row['logo_path']));
        self::assertSame(0, $this->storedFiles('tmp'), 'the staged file is gone');
    }

    #[Group('AC-81')]
    public function testAnUnknownOrForgedPreviewTokenIsRefused(): void
    {
        $response = $this->saveBranding(['staged_logo' => str_repeat('a', 32) . '.png']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Choose the logo file again', $this->body($response));
        self::assertSame(['logo_path' => null, 'brand_color' => null], $this->brandingRow());
    }

    #[Group('AC-82')]
    public function testAHexColorIsSavedAndAppliedToThePortal(): void
    {
        $this->saveBranding(['brand_color' => '#aa3300']);

        self::assertSame('#AA3300', $this->brandingRow()['brand_color']);
        $html = $this->body($this->get('/trainer'));
        self::assertStringContainsString('--brand-primary: #AA3300', $html);
        self::assertStringContainsString('--brand-primary-rgb: 170, 51, 0', $html);
        self::assertSame(1, preg_match('/<style nonce="([^"]+)">:root \{/', $html), 'brand variables are in a nonce-protected style block');
    }

    #[Group('AC-82')]
    public function testTheBrandingPageOffersLiveColorPreviewThatWorksWithoutScripts(): void
    {
        $html = $this->body($this->get('/trainer/branding'));

        self::assertStringContainsString('id="brand-preview"', $html);
        self::assertStringContainsString('/assets/js/branding-preview.js', $html);
        self::assertStringContainsString('name="action" value="preview"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    #[Group('AC-82')]
    public function testResetReturnsToTheDefaultLogoAndColorAndRemovesTheOldFile(): void
    {
        $this->saveBranding(['brand_color' => '#AA3300'], ['logo' => $this->logoUpload()]);
        $old = (string) $this->brandingRow()['logo_path'];

        $response = $this->post('/trainer/branding/reset');

        self::assertSame('/trainer/branding?notice=reset', $this->location($response));
        self::assertSame(['logo_path' => null, 'brand_color' => null], $this->brandingRow());
        $html = $this->body($this->get('/trainer'));
        self::assertStringNotContainsString('#AA3300', $html);
        self::assertStringContainsString('/assets/img/default_logo.svg', $html);
        self::assertTrue($this->storage->exists(FileStorage::LOGOS, $old), 'still there until the worker runs');
        $worker = $this->container->get(OutboxWorker::class);
        self::assertInstanceOf(OutboxWorker::class, $worker);
        $worker->runOnce();
        self::assertFalse($this->storage->exists(FileStorage::LOGOS, $old), 'removed after the commit by the worker');
    }

    #[Group('AC-81')]
    public function testReplacingTheLogoKeepsTheOldFileUntilTheWorkerRemovesIt(): void
    {
        $this->saveBranding([], ['logo' => $this->logoUpload()]);
        $first = (string) $this->brandingRow()['logo_path'];

        $this->saveBranding([], ['logo' => $this->logoUpload(ImageFixtures::jpeg(30, 30), 'b.jpg')]);

        $second = (string) $this->brandingRow()['logo_path'];
        self::assertNotSame($first, $second);
        self::assertTrue($this->storage->exists(FileStorage::LOGOS, $first));
        $worker = $this->container->get(OutboxWorker::class);
        self::assertInstanceOf(OutboxWorker::class, $worker);
        $worker->runOnce();
        self::assertFalse($this->storage->exists(FileStorage::LOGOS, $first));
        self::assertTrue($this->storage->exists(FileStorage::LOGOS, $second));
    }

    #[Group('AC-82')]
    public function testSavingJustTheColorKeepsTheExistingLogo(): void
    {
        $this->saveBranding([], ['logo' => $this->logoUpload()]);
        $logo = $this->brandingRow()['logo_path'];

        $this->saveBranding(['brand_color' => '#0000FF'], ['logo' => ImageFixtures::file('', 'x', UPLOAD_ERR_NO_FILE)]);

        self::assertSame(['logo_path' => $logo, 'brand_color' => '#0000FF'], $this->brandingRow());
        self::assertSame(1, $this->storedFiles('logos'));
    }

    #[Group('AC-84')]
    public function testALogoLargerThanTwoHundredPixelsIsResizedAutomatically(): void
    {
        $this->saveBranding([], ['logo' => $this->logoUpload(ImageFixtures::png(900, 450))]);

        $info = getimagesizefromstring((string) $this->storage->get(FileStorage::LOGOS, (string) $this->brandingRow()['logo_path']));

        self::assertIsArray($info);
        self::assertSame([200, 100], [$info[0], $info[1]]);
    }

    #[Group('AC-84')]
    public function testAFileOfTheWrongTypeIsRefusedWithAClearMessageNamingTheField(): void
    {
        $response = $this->saveBranding([], ['logo' => ImageFixtures::file('GIF89a' . str_repeat("\0", 30), 'logo.png')]);
        $html = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('This file type is not allowed. Upload a PNG, JPG or SVG image.', $html);
        self::assertStringContainsString('href="#field-logo"', $html);
    }

    #[Group('AC-84')]
    public function testAFileOverTwoMegabytesIsRefused(): void
    {
        $response = $this->saveBranding([], ['logo' => ImageFixtures::file(ImageFixtures::pngOfSize(2 * 1024 * 1024 + 1))]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('larger than 2 MB', $this->body($response));
    }

    #[Group('AC-84')]
    public function testAFileOfExactlyTwoMegabytesIsAccepted(): void
    {
        $response = $this->saveBranding([], ['logo' => ImageFixtures::file(ImageFixtures::pngOfSize(2 * 1024 * 1024))]);

        self::assertSame(302, $response->getStatusCode());
    }

    #[Group('AC-84')]
    public function testAnSvgWithAScriptIsRefused(): void
    {
        $response = $this->saveBranding([], ['logo' => ImageFixtures::file(ImageFixtures::svg('<script>alert(1)</script>'), 'x.svg')]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('scripts, external links or unsupported content', $this->body($response));
        self::assertSame(0, $this->storedFiles('logos'));
    }

    #[Group('AC-82')]
    public function testAnInvalidHexColorIsRefusedNamingTheColorField(): void
    {
        foreach (['red', '#12345', '#GGGGGG', '', '#1234567', 'javascript:alert(1)'] as $bad) {
            $response = $this->saveBranding(['brand_color' => $bad]);

            self::assertSame(422, $response->getStatusCode(), $bad);
            self::assertStringContainsString('href="#field-brand_color"', $this->body($response), $bad);
        }
        self::assertSame(['logo_path' => null, 'brand_color' => null], $this->brandingRow());
    }

    #[Group('AC-82'), Group('AC-84')]
    public function testAColorAndLogoProblemAreReportedTogether(): void
    {
        $response = $this->saveBranding(['brand_color' => 'nope'], ['logo' => ImageFixtures::file('not an image')]);
        $html = $this->body($response);

        self::assertStringContainsString('href="#field-brand_color"', $html);
        self::assertStringContainsString('href="#field-logo"', $html);
    }

    public function testOnlyTheTrainerReachesTheBrandingPage(): void
    {
        foreach (['coach', 'player', 'super_admin'] as $role) {
            $this->signInAs($role);

            self::assertSame(403, $this->get('/trainer/branding')->getStatusCode(), $role);
            self::assertSame(403, $this->saveBranding()->getStatusCode(), $role);
            self::assertSame(403, $this->post('/trainer/branding/reset')->getStatusCode(), $role);
        }
    }

    public function testSavingAndResettingAreAuditedWithoutPersonalData(): void
    {
        $this->saveBranding([], ['logo' => $this->logoUpload()]);
        $this->post('/trainer/branding/reset');

        $payload = (string) $this->scalar("SELECT payload FROM audit_log WHERE action = 'branding.updated' AND subject_id = :o", ['o' => $this->orgId]);
        self::assertSame(['logo_changed' => true, 'color_changed' => true], json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'branding.reset' AND subject_id = :o", ['o' => $this->orgId]));
    }
}
