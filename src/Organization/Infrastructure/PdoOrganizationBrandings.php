<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Organization\Domain\Branding;
use App\Organization\Domain\OrganizationBrandings;
use App\Shared\Domain\HexColor;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Pdo\TenantRepository;

final class PdoOrganizationBrandings extends TenantRepository implements OrganizationBrandings
{
    public function find(OrganizationId $organization): ?Branding
    {
        return $this->fetch($organization, '');
    }

    public function lock(OrganizationId $organization): ?Branding
    {
        return $this->fetch($organization, ' FOR UPDATE');
    }

    public function save(OrganizationId $organization, ?string $logoPath, ?HexColor $color): void
    {
        $stmt = $this->pdo->prepare('UPDATE organizations SET logo_path = :logo, brand_color = :color, updated_at = now() WHERE id = :organization_id');
        $stmt->execute($this->scopeParams($organization, ['logo' => $logoPath, 'color' => $color?->value]));
    }

    private function fetch(OrganizationId $organization, string $suffix): ?Branding
    {
        $stmt = $this->pdo->prepare('SELECT business_name, logo_path, brand_color FROM organizations WHERE id = :organization_id' . $suffix);
        $stmt->execute($this->scopeParams($organization));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new Branding(
            $organization,
            (string) $row['business_name'],
            is_string($row['logo_path']) ? $row['logo_path'] : null,
            is_string($row['brand_color']) ? HexColor::fromString($row['brand_color']) : null,
        );
    }
}
