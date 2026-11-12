<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

final class ImpersonationBannerTest extends ImpersonationTestCase
{
    #[Group('AC-52')]
    public function testTheBannerIsOnEveryPageWhileImpersonating(): void
    {
        $this->startAsCoach();

        foreach (['/coach', '/coach/schedule', '/profile', '/admin/users' /* 403 page */, '/no-such-page' /* 404 page */] as $path) {
            $html = $this->body($this->get($path));
            self::assertStringContainsString('class="impersonation-banner"', $html, $path);
            self::assertStringContainsString('Viewing as Cora Coach |', $html, $path);
            self::assertStringContainsString('Exit Impersonation', $html, $path);
        }
    }

    #[Group('AC-52'), Group('AC-53')]
    public function testExitIsAPostFormWithACsrfToken(): void
    {
        $this->startAsCoach();

        $html = $this->body($this->get('/coach'));

        self::assertSame(1, preg_match('#<form method="post" action="/impersonation/exit">\s*<input type="hidden" name="_csrf" value="[^"]+">\s*<button[^>]*>Exit Impersonation</button>#', $html));
    }

    #[Group('AC-52')]
    public function testTheBannerNamesTheUserWithoutTheAdminsOwnDetailsAndEscapesTheName(): void
    {
        $this->pdo->prepare('UPDATE users SET name = :n WHERE id = :u')->execute(['n' => '<b>Cora</b> & Co', 'u' => $this->coach]);
        $this->startAsCoach();

        $html = $this->body($this->get('/coach'));

        self::assertStringContainsString('Viewing as &lt;b&gt;Cora&lt;/b&gt; &amp; Co |', $html);
        self::assertStringNotContainsString('Viewing as <b>', $html);
    }

    #[Group('AC-52')]
    public function testThereIsNoBannerOutsideAnImpersonation(): void
    {
        self::assertStringNotContainsString('impersonation-banner', $this->body($this->get('/admin/users')));
        $this->startAsCoach();
        $this->post('/impersonation/exit');
        self::assertStringNotContainsString('impersonation-banner', $this->body($this->get('/admin/users')));
        $this->cookies = [];
        self::assertStringNotContainsString('impersonation-banner', $this->body($this->get('/login')));
    }

    #[Group('AC-52')]
    public function testTheBannerIsStickyAndColorDistinctInTheStylesheet(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/app.css');

        self::assertMatchesRegularExpression('/\.impersonation-banner\s*\{[^}]*position:\s*sticky;[^}]*top:\s*0/', $css);
        self::assertMatchesRegularExpression('/\.impersonation-banner\s*\{[^}]*background:\s*var\(--gray-950\);[^}]*color:\s*#FFD54F/', $css);
    }
}
