<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\MediaAccess;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Infrastructure\Storage\FileStorage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /media/{kind}/{id}`: serves stored images from the private storage after an authorization
 * check. Anything the viewer may not see answers 404, the same as a missing file (design C11, AC-83).
 */
final class MediaController
{
    public function __construct(
        private readonly MediaAccess $access,
        private readonly FileStorage $storage,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    /** @param array<string, string> $args */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $viewer = $request->getAttribute('current_user');
        if (!$viewer instanceof CurrentUser) {
            throw new Forbidden();
        }
        $media = $this->access->locate($viewer->id, $viewer->role === Role::SuperAdmin, (string) ($args['kind'] ?? ''), (string) ($args['id'] ?? ''));
        $bytes = $media === null ? null : $this->storage->get($media->storageKind, $media->name);
        if ($media === null || $bytes === null) {
            throw new NotFound();
        }

        // The file name is random and changes on every replacement, so it is a perfect validator.
        $etag = '"' . substr(hash('sha256', $media->name), 0, 32) . '"';
        $response = $this->responses->createResponse(200)
            ->withHeader('Content-Type', $media->mime())
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withHeader('ETag', $etag);
        if ($media->isSvg()) {
            $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($bytes);

        return $response->withHeader('Content-Length', (string) strlen($bytes));
    }
}
