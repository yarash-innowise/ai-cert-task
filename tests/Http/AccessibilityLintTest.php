<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Organization\Domain\BrandPalette;
use PHPUnit\Framework\Attributes\Group;
use Tests\Http\Availability\AvailabilityHttpTestCase;

/**
 * Structural WCAG 2.1 AA lint over every rendered screen (AC-89): one h1, landmarks, skip link,
 * labels bound to every control, error messages linked with aria-describedby / aria-invalid,
 * unique ids, resolvable references, alt text, named links and buttons, no positive tabindex.
 * It cannot judge keyboard order or perceived contrast; those are covered by the static checks at
 * the end (focus style, touch target, 320 px layout, contrast of the design tokens) and by the
 * manual walk recorded in docs/accessibility-epic-01.md.
 */
#[Group('AC-89')]
final class AccessibilityLintTest extends AvailabilityHttpTestCase
{
    /** @var list<string> */
    private array $screens = [];

    /** @return list<\DOMElement> the elements an XPath query matches */
    private function nodes(\DOMXPath $xp, string $query, ?\DOMNode $context = null): array
    {
        $found = $xp->query($query, $context);
        $elements = [];
        foreach ($found === false ? [] : $found as $node) {
            if ($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function hits(\DOMXPath $xp, string $query, ?\DOMNode $context = null): int
    {
        return count($this->nodes($xp, $query, $context));
    }

    /** @return list<string> the rule violations of one page */
    private function violations(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $bad = [];

        $h1 = $this->hits($xp, '//h1');
        if ($h1 !== 1) {
            $bad[] = "expected one h1, found $h1";
        }
        if ($this->hits($xp, '/html[@lang="en"]') !== 1) {
            $bad[] = 'html lang is missing';
        }
        if (trim((string) $xp->evaluate('string(//title)')) === '') {
            $bad[] = 'title is empty';
        }
        if ($this->hits($xp, '//a[@class="skip-link"][@href="#main"]') !== 1 || $this->hits($xp, '//main[@id="main"]') !== 1) {
            $bad[] = 'skip link or main landmark is missing';
        }
        foreach (['//header[@role="banner"]', '//footer[@role="contentinfo"]'] as $landmark) {
            if ($this->hits($xp, $landmark) !== 1) {
                $bad[] = "landmark $landmark is not present exactly once";
            }
        }
        if ($this->hits($xp, '//*[@role="status"]') < 1) {
            $bad[] = 'no status region for announcements';
        }

        // Heading levels never jump down by more than one
        $level = 0;
        foreach ($this->nodes($xp, '//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $now = (int) substr($heading->nodeName, 1);
            if ($level !== 0 && $now > $level + 1) {
                $bad[] = 'heading level jumps from h' . $level . ' to h' . $now . ' ("' . trim($heading->textContent) . '")';
            }
            $level = $now;
        }

        // Unique ids, resolvable references
        $ids = [];
        foreach ($this->nodes($xp, '//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            if (isset($ids[$id])) {
                $bad[] = "duplicate id $id";
            }
            $ids[$id] = true;
        }
        foreach (['aria-describedby', 'aria-labelledby', 'aria-controls'] as $attribute) {
            foreach ($this->nodes($xp, "//*[@$attribute]") as $node) {
                foreach (preg_split('/\s+/', trim($node->getAttribute($attribute))) ?: [] as $ref) {
                    if (!isset($ids[$ref])) {
                        $bad[] = "$attribute points to missing id $ref";
                    }
                }
            }
        }
        foreach ($this->nodes($xp, '//a[starts-with(@href, "#")]') as $anchor) {
            $target = substr($anchor->getAttribute('href'), 1);
            if ($target !== '' && !isset($ids[$target])) {
                $bad[] = "in-page link to missing id $target";
            }
        }

        // Every control has a label; radios and checkboxes sit in a fieldset with a legend
        foreach ($this->nodes($xp, '//input[not(@type="hidden" or @type="submit" or @type="button" or @type="reset" or @type="image")]|//select|//textarea') as $control) {
            $id = $control->getAttribute('id');
            $labelled = ($id !== '' && $this->hits($xp, '//label[@for="' . $id . '"]') > 0)
                || $this->hits($xp, 'ancestor::label', $control) > 0
                || trim($control->getAttribute('aria-label')) !== ''
                || $control->getAttribute('aria-labelledby') !== '';
            if (!$labelled) {
                $bad[] = 'control without a label: ' . ($control->getAttribute('name') ?: $control->nodeName);
            }
            if (in_array($control->getAttribute('type'), ['radio', 'checkbox'], true)
                && $this->hits($xp, 'ancestor::fieldset/legend', $control) === 0
                && $this->hits($xp, '//input[@name="' . $control->getAttribute('name') . '"][@type="radio" or @type="checkbox"]') > 1) {
                $bad[] = 'group of choices without a fieldset legend: ' . $control->getAttribute('name');
            }
            if ($control->getAttribute('aria-invalid') === 'true' && $control->getAttribute('aria-describedby') === '') {
                $bad[] = 'invalid control without an error description: ' . $control->getAttribute('name');
            }
        }
        foreach ($this->nodes($xp, '//*[contains(concat(" ", normalize-space(@class), " "), " field--error ")]') as $field) {
            if ($this->hits($xp, './/*[@aria-invalid="true"]', $field) === 0 && $this->hits($xp, './/fieldset|self::fieldset', $field) === 0) {
                $bad[] = 'field marked as erroneous without aria-invalid';
            }
        }
        foreach ($this->nodes($xp, '//*[@id="error-summary"]') as $summary) {
            if ($summary->getAttribute('role') !== 'alert' || $summary->getAttribute('tabindex') !== '-1') {
                $bad[] = 'error summary must be an alert that can take focus';
            }
        }

        // Images, links, buttons, tab order
        foreach ($this->nodes($xp, '//img[not(@alt)]') as $img) {
            $bad[] = 'image without alt: ' . $img->getAttribute('src');
        }
        foreach ($this->nodes($xp, '//a|//button') as $control) {
            if (trim($control->textContent) === '' && trim($control->getAttribute('aria-label')) === '' && $this->hits($xp, './/img[@alt!=""]', $control) === 0) {
                $bad[] = $control->nodeName . ' without an accessible name';
            }
        }
        foreach ($this->nodes($xp, '//*[@tabindex]') as $node) {
            if ((int) $node->getAttribute('tabindex') > 0) {
                $bad[] = 'positive tabindex';
            }
        }
        foreach ($this->nodes($xp, '//table') as $table) {
            if ($this->hits($xp, './/th', $table) === 0) {
                $bad[] = 'table without header cells';
            }
        }
        foreach ($this->nodes($xp, '//form[@method="post"]') as $form) {
            if ($this->hits($xp, './/input[@name="_csrf"]', $form) === 0) {
                $bad[] = 'POST form without a CSRF field';
            }
        }

        return $bad;
    }

    private function lint(string $label, \Psr\Http\Message\ResponseInterface $response, ?int $status = null): void
    {
        if ($status !== null) {
            self::assertSame($status, $response->getStatusCode(), $label . ' answered ' . $response->getStatusCode());
        }
        $html = $this->body($response);
        self::assertStringContainsString('<html', $html, $label . ' is not an HTML page');
        $this->screens[] = $label;
        self::assertSame([], $this->violations($html), $label);
    }

    private function page(string $path, int $status = 200): void
    {
        $this->lint('GET ' . $path, $this->get($path), $status);
    }

    public function testTheLinterCatchesKnownProblems(): void
    {
        $bad = '<html><head><title>x</title></head><body><h1>a</h1><h1>b</h1><h3>c</h3>'
            . '<form method="post"><input name="q"><input id="e" aria-invalid="true" aria-describedby="nope"><label for="e">E</label><img src="a.png"><a href="#gone"></a></form></body></html>';

        $found = implode("\n", $this->violations($bad));

        foreach (['expected one h1', 'html lang is missing', 'skip link or main landmark is missing', 'heading level jumps', 'control without a label: q', 'aria-describedby points to missing id nope', 'image without alt', 'in-page link to missing id gone', 'a without an accessible name', 'POST form without a CSRF field'] as $expected) {
            self::assertStringContainsString($expected, $found);
        }
    }

    public function testPublicScreens(): void
    {
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $this->cookies = [];
        foreach (['/', '/login', '/forgot-password', '/verify-email/resend', '/join/' . $code, '/join/' . $code . '/register', '/register/prefill/' . str_repeat('A', 43), '/join/nope', '/invitations/nope', '/reset-password/nope', '/verify-email/nope', '/setup/nope'] as $path) {
            $response = $this->get($path);
            $this->lint('GET ' . $path, $response);
        }
        $this->lint('GET /missing-page', $this->get('/missing-page'), 404);
        self::assertGreaterThan(10, count($this->screens));
    }

    public function testFormsReRenderedWithErrorsAreAnnouncedAndLinked(): void
    {
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $this->cookies = [];

        $register = $this->post('/join/' . $code . '/register', ['name' => '', 'email' => 'x', 'password' => '', 'phone' => '', 'player_for' => '', 'birth_date' => '', 'gender' => '']);
        $this->lint('POST register with errors', $register, 422);
        $prefill = $this->post('/register/prefill/' . str_repeat('A', 43), ['name' => '', 'email' => 'x']);
        $this->lint('POST prefill register with errors', $prefill, 422);
        $login = $this->post('/login', ['email' => '', 'password' => '']);
        $this->lint('POST login with errors', $login);

        $html = $this->body($register);
        self::assertStringContainsString('id="error-summary"', $html);
        self::assertStringContainsString('href="#field-email"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="error-email"', $html);
    }

    public function testPlayerAndParentScreens(): void
    {
        [, $org] = $this->trainerWithOrganization();
        [, $otherOrg] = $this->trainerWithOrganization('Beta Academy');
        [, $code] = $this->shareLink($otherOrg);
        $parent = $this->signInAs('player', 'pat@example.com', 'Pat Parent');
        $me = $this->selfOf($parent);
        $mia = $this->childOf($parent);
        $this->associate($me, $org);
        $this->associate($mia, $org);

        $paths = ['/family', '/family/events', '/family/children', '/family/children/new', "/family/children/$mia/edit", "/family/children/$mia/login", "/family/children/$mia/settings", '/family/me', '/family/context', '/family/approvals', '/family/availability', "/family/availability/$mia", '/family/content', '/family/progress', '/family/feedback', '/family/profile', '/family/tokens', '/profile', '/join/' . $code];
        foreach ($paths as $path) {
            $this->page($path);
        }
        $this->lint('POST add child with errors', $this->addChild(['first_name' => '']), 422);
        $this->lint('GET /family/forbidden-area', $this->get('/trainer'), 403);
    }

    public function testChildLoginScreens(): void
    {
        [, $org] = $this->trainerWithOrganization();
        $parent = $this->createUser('player', 'pat@example.com');
        $mia = $this->childOf($parent);
        $this->associate($mia, $org);
        $this->signInAsChildLogin($parent, $mia);

        foreach (['/family', '/family/events', '/family/requests', '/family/profile', '/profile'] as $path) {
            $this->page($path);
        }
    }

    public function testTrainerScreens(): void
    {
        [, $org] = $this->trainerSignedIn();
        $parent = $this->createUser('player', 'pat@example.com');
        $mia = $this->childOf($parent);
        $this->associate($mia, $org);
        $coach = $this->coachIn($org);
        $this->shareLink($org);

        $paths = ['/trainer', '/trainer/events', '/trainer/players', "/trainer/players/$mia", '/trainer/coaches', "/trainer/coaches/$coach", "/trainer/coaches/$coach/schedule", '/trainer/links', '/trainer/branding', '/trainer/availability', "/trainer/availability/$mia", '/trainer/assignments', '/profile', "/coaches/$coach"];
        foreach ($paths as $path) {
            $this->page($path);
        }
        $this->lint('POST invite with errors', $this->invite('not-an-email'), 422);
    }

    public function testCoachScreens(): void
    {
        [, $org] = $this->trainerWithOrganization();
        $coach = $this->coachIn($org);
        $this->signInAsCoachUser($coach);

        foreach (['/coach', '/coach/schedule', '/profile'] as $path) {
            $this->page($path);
        }
    }

    public function testAdminScreensAndTheImpersonationBanner(): void
    {
        $this->signInAs('super_admin', 'ada@example.com', 'Ada Admin');
        $target = $this->createUser('player', 'pat@example.com', name: 'Pat Parent');

        foreach (['/admin', '/admin/users', '/admin/users/new', "/admin/users/$target/edit", "/admin/users/$target/deactivate", "/admin/users/$target/delete", "/admin/users/$target/impersonate", '/admin/impersonations', '/profile'] as $path) {
            $this->page($path);
        }
        $this->lint('POST new trainer with errors', $this->post('/admin/users/new', ['email' => '']), 422);

        $this->post("/admin/users/$target/impersonate");
        $page = $this->get('/family');
        $this->lint('impersonated /family', $page, 200);
        $html = $this->body($page);
        self::assertStringContainsString('role="region" aria-label="Impersonation"', $html);
        self::assertMatchesRegularExpression('#<button class="impersonation-banner__exit" type="submit">Exit Impersonation</button>#', $html);
    }

    public function testBrandBannerAndContextSelectorAreFocusableControls(): void
    {
        [$trainer, $org] = $this->trainerSignedIn();
        $this->pdo->prepare("UPDATE organizations SET brand_color = '#0A5CD6' WHERE id = :o")->execute(['o' => $org]);
        $html = $this->body($this->get('/trainer'));
        self::assertStringContainsString('alt="Logo of Ace Tennis Academy"', $html);

        $this->signOut();
        $parent = $this->signInAs('player', 'pat@example.com');
        $this->associate($this->selfOf($parent), $org);
        $selector = $this->body($this->get('/family'));
        self::assertStringContainsString('<label for="context-select">Training with</label>', $selector);
        self::assertStringContainsString('<select class="input" id="context-select"', $selector);
        self::assertStringContainsString('<button class="button button--secondary" type="submit">Switch</button>', $selector);
        self::assertNotSame('', $trainer);
    }

    public function testNoTemplateDisablesEscaping(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\|\s*raw\b|autoescape\s+false|\{%\s*autoescape\s+[\'"]?false/', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders);
    }

    public function testStylesheetKeepsFocusTouchTargetsAndMobileLayout(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/app.css');
        $tokens = (string) file_get_contents(__DIR__ . '/../../public/assets/css/tokens.css');

        self::assertStringContainsString(':focus-visible { outline: var(--focus-ring)', $css);
        self::assertStringContainsString('--control-height: 44px', $tokens);
        self::assertMatchesRegularExpression('/\.button \{[^}]*min-height: var\(--control-height\)/s', $css);
        self::assertMatchesRegularExpression('/\.input \{[^}]*min-height: var\(--control-height\)/s', $css);
        self::assertMatchesRegularExpression('/\.link-button \{[^}]*min-height: 44px/s', $css);
        // Mobile first: base rules have no min-width (a 320 px phone gets the single column); wide rules sit in min-width queries only
        self::assertDoesNotMatchRegularExpression('/@media \(max-width/', $css);
        self::assertStringContainsString('.table-wrap { overflow-x: auto; }', $css);
        self::assertMatchesRegularExpression('/\.filter-bar \{[^}]*grid-template-columns: 1fr/s', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        $layout = (string) file_get_contents(__DIR__ . '/../../templates/layout/base.twig');
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $layout);
    }

    public function testDesignTokenColorPairsMeetAaContrast(): void
    {
        $pairs = [
            'body text on page' => ['#0D0D0D', '#F3F3F3', 4.5],
            'body text on white' => ['#0D0D0D', '#FFFFFF', 4.5],
            'hint text on page' => ['#5E5E5E', '#F3F3F3', 4.5],
            'hint text on white' => ['#5E5E5E', '#FFFFFF', 4.5],
            'default brand link on page' => ['#006600', '#F3F3F3', 4.5],
            'on-brand button text' => ['#FFFFFF', '#006600', 4.5],
            'error text on white' => ['#B3261E', '#FFFFFF', 4.5],
            'error text on error summary' => ['#B3261E', '#FCEDEC', 4.5],
            'impersonation banner text' => ['#FFD54F', '#0D0D0D', 4.5],
            'skip link' => ['#FFFFFF', '#0D0D0D', 4.5],
            'focus ring on page' => ['#0D0D0D', '#F3F3F3', 3.0],
            'input border on white' => ['#868686', '#FFFFFF', 3.0],
        ];
        foreach ($pairs as $name => [$foreground, $background, $minimum]) {
            self::assertGreaterThanOrEqual($minimum, BrandPalette::contrastRatio($foreground, $background), $name);
        }
    }
}
