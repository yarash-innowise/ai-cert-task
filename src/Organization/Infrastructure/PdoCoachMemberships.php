<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure;

use App\Organization\Domain\CoachAlreadyActive;
use App\Organization\Domain\CoachMembership;
use App\Organization\Domain\CoachMemberships;
use App\Shared\Domain\OrganizationId;
use DateTimeImmutable;
use PDO;
use PDOException;

final class PdoCoachMemberships implements CoachMemberships
{
    public function __construct(private readonly PDO $pdo) {}

    public function activeOf(string $coachUserId): ?CoachMembership
    {
        $stmt = $this->pdo->prepare("SELECT * FROM coach_memberships WHERE coach_user_id = :u AND status = 'active'");
        $stmt->execute(['u' => $coachUserId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function lockOrganization(OrganizationId $organization): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM organizations WHERE id = :o FOR SHARE');
        $stmt->execute(['o' => $organization->value]);
        $stmt->fetchAll();
    }

    public function endInInactiveOrganizations(string $coachUserId, DateTimeImmutable $at): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE coach_memberships m SET status = 'ended', ended_at = :at
               FROM organizations o
              WHERE m.organization_id = o.id AND m.coach_user_id = :u AND m.status = 'active'
                AND (o.status <> 'active' OR EXISTS (SELECT 1 FROM users t WHERE t.id = o.owner_user_id AND t.status <> 'active'))",
        );
        $stmt->execute(['u' => $coachUserId, 'at' => $at->format('Y-m-d H:i:s.uP')]);

        return $stmt->rowCount();
    }

    public function start(OrganizationId $organization, string $coachUserId, ?string $invitationId, DateTimeImmutable $at): CoachMembership
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO coach_memberships (organization_id, coach_user_id, invitation_id, status, started_at)
                 VALUES (:o, :u, :i, 'active', :at) RETURNING *",
            );
            $stmt->execute(['o' => $organization->value, 'u' => $coachUserId, 'i' => $invitationId, 'at' => $at->format('Y-m-d H:i:s.uP')]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'coach_memberships_one_active_per_coach')) {
                throw new CoachAlreadyActive($e);
            }
            throw $e;
        }
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): CoachMembership
    {
        return new CoachMembership((string) $row['id'], (string) $row['organization_id'], (string) $row['coach_user_id'], new DateTimeImmutable((string) $row['started_at']));
    }
}
