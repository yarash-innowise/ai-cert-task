<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Domain\OrganizationId;

/**
 * Where the signed-in user is acting for this request: the active organization (tenant) and, for
 * families, the acting player profile. Both are null for users without any organization (for
 * example a Super Admin, who is not tenant-bound).
 */
final readonly class RequestContext
{
    public const ATTRIBUTE = 'request_context';

    public function __construct(
        public ?OrganizationId $organizationId = null,
        public ?string $profileId = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public static function from(\Psr\Http\Message\ServerRequestInterface $request): self
    {
        $context = $request->getAttribute(self::ATTRIBUTE);

        return $context instanceof self ? $context : self::none();
    }
}
