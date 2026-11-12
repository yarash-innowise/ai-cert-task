<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Shared\Domain\ContextOption;
use App\Shared\Domain\ContextSource;
use App\Shared\Domain\OrganizationId;
use PDO;

/** A Trainer acts in the organization they own, as long as it is active (fixed context, no switching). */
final class OwnedOrganizationContextSource implements ContextSource
{
    public function __construct(private readonly PDO $pdo) {}

    public function optionsFor(string $userId, string $role): array
    {
        if ($role !== 'trainer') {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT id FROM organizations WHERE owner_user_id = :u AND status = 'active'");
        $stmt->execute(['u' => $userId]);
        $id = $stmt->fetchColumn();
        $organization = is_string($id) ? OrganizationId::tryFromString($id) : null;

        return $organization === null ? [] : [new ContextOption($organization)];
    }
}
