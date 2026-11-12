<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Pdo;

use App\Shared\Domain\OrganizationId;
use PDO;

/**
 * Convention base for every repository of tenant data (design D8, AC-10):
 *  - every public method takes an OrganizationId (there is no "find by id" without one);
 *  - every SQL statement scopes with {@see self::scope()} and binds {@see self::scopeParams()};
 *  - a record outside the organization is reported as missing (the caller answers 404), never as forbidden.
 */
abstract class TenantRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    /** SQL condition limiting a statement to one organization, for example `WHERE id = :id AND ` + scope('t'). */
    protected function scope(string $alias = ''): string
    {
        return ($alias === '' ? '' : $alias . '.') . 'organization_id = :organization_id';
    }

    /**
     * @param array<string, scalar|null> $params the statement's own parameters
     * @return array<string, scalar|null> the same parameters plus the organization binding
     */
    protected function scopeParams(OrganizationId $organization, array $params = []): array
    {
        return [...$params, 'organization_id' => $organization->value];
    }
}
