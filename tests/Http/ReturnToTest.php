<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\Attributes\Group;

#[Group('AC-9')]
final class ReturnToTest extends AppHttpTestCase
{
    public function testLoginIgnoresAnExternalReturnToAndGoesToTheDashboard(): void
    {
        $this->createUser('coach', 'who@example.com');

        foreach (['//evil.com', 'https://evil.com/x', '/\\evil.com'] as $evil) {
            $this->cookies = [];
            $response = $this->post('/login', ['email' => 'who@example.com', 'password' => 'correct horse battery', 'return_to' => $evil]);
            self::assertSame('/coach', $this->location($response), $evil);
        }
    }

    public function testLoginFormDropsAnExternalReturnToFromTheQuery(): void
    {
        $html = $this->body($this->get('/login?return_to=' . rawurlencode('//evil.com')));

        self::assertStringNotContainsString('evil.com', $html);
        self::assertStringNotContainsString('name="return_to"', $html);
    }

    public function testLoginFormKeepsARelativeReturnTo(): void
    {
        $html = $this->body($this->get('/login?return_to=' . rawurlencode('/family/children')));

        self::assertStringContainsString('name="return_to" value="/family/children"', $html);
    }

    public function testRelativeReturnToIsFollowedAfterLogin(): void
    {
        $this->createUser('player', 'who@example.com');
        $response = $this->post('/login', ['email' => 'who@example.com', 'password' => 'correct horse battery', 'return_to' => '/family/children']);

        self::assertSame('/family/children', $this->location($response));
    }
}
