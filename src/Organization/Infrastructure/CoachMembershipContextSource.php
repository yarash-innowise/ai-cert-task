<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Shared\Domain\ContextOption;
use App\Shared\Domain\ContextSource;
use App\Shared\Domain\OrganizationId;
use PDO;

/** A Coach acts in the organization of their active membership, as long as that organization is active. */
final class CoachMembershipContextSource implements ContextSource
{
    public function __construct(private readonly PDO $pdo) {}

    public function optionsFor(string $userId, string $role): array
    {
        if ($role !== 'coach') {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.organization_id FROM coach_memberships m
             JOIN organizations o ON o.id = m.organization_id
             WHERE m.coach_user_id = :u AND m.status = 'active' AND o.status = 'active'",
        );
        $stmt->execute(['u' => $userId]);
        $id = $stmt->fetchColumn();
        $organization = is_string($id) ? OrganizationId::tryFromString($id) : null;

        return $organization === null ? [] : [new ContextOption($organization)];
    }
}
