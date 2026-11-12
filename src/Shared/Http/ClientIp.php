<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Client address for throttling and audit. X-Forwarded-For is honoured only when the
 * direct peer is a configured trusted proxy; otherwise the socket address is used.
 */
final class ClientIp
{
    /** @param list<string> $trustedProxies */
    public function __construct(private readonly array $trustedProxies = []) {}

    public function fromRequest(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : '0.0.0.0';

        if (!in_array($remote, $this->trustedProxies, true)) {
            return $remote;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded === '') {
            return $remote;
        }

        $chain = array_map('trim', explode(',', $forwarded));
        for ($i = count($chain) - 1; $i >= 0; --$i) {
            $candidate = $chain[$i];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!in_array($candidate, $this->trustedProxies, true)) {
                return $candidate;
            }
        }

        return $remote;
    }
}
