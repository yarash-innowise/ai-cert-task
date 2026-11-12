<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Shared\Http\ClientIp;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('AC-3')]
final class ClientIpTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(string $remote, array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', '/', $headers, null, '1.1', ['REMOTE_ADDR' => $remote]);
    }

    public function testForwardedHeaderIsIgnoredWhenThePeerIsNotATrustedProxy(): void
    {
        $ip = new ClientIp(['10.0.0.1']);

        self::assertSame('198.51.100.7', $ip->fromRequest($this->request('198.51.100.7', ['X-Forwarded-For' => '1.2.3.4'])));
    }

    public function testForwardedHeaderIsUsedBehindATrustedProxy(): void
    {
        $ip = new ClientIp(['10.0.0.1']);

        self::assertSame('1.2.3.4', $ip->fromRequest($this->request('10.0.0.1', ['X-Forwarded-For' => '1.2.3.4'])));
        self::assertSame('1.2.3.4', $ip->fromRequest($this->request('10.0.0.1', ['X-Forwarded-For' => '9.9.9.9, 1.2.3.4, 10.0.0.1'])), 'rightmost untrusted hop wins, so a spoofed left part is ignored');
        self::assertSame('10.0.0.1', $ip->fromRequest($this->request('10.0.0.1', ['X-Forwarded-For' => 'garbage'])));
    }
}
