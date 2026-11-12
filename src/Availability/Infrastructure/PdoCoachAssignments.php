<?php

declare(strict_types=1);

namespace App\Availability\Infrastructure;

use App\Availability\Domain\AssignmentAlreadyExists;
use App\Availability\Domain\AssignmentStatus;
use App\Availability\Domain\CoachAssignment;
use App\Availability\Domain\CoachAssignments;
use App\Availability\Domain\TimeRange;
use App\Shared\Domain\OrganizationId;
use App\Shared\Domain\Uuid;
use App\Shared\Infrastructure\Pdo\TenantRepository;
use DateTimeImmutable;
use PDOException;

final class PdoCoachAssignments extends TenantRepository implements CoachAssignments
{
    private const SELECT = 'SELECT a.id, a.organization_id, a.event_ref, a.event_date, a.starts_at, a.ends_at, a.coach_user_id,
            u.name AS coach_name, a.status, a.override_id, o.reason AS override_reason, a.change_note, a.created_at
         FROM coach_assignments a
         JOIN users u ON u.id = a.coach_user_id
         LEFT JOIN coach_availability_overrides o ON o.id = a.override_id
         WHERE a.organization_id = :organization_id';

    public function recordOverride(OrganizationId $organization, string $eventRef, string $coachUserId, string $reason, string $overriddenBy): string
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO coach_availability_overrides (organization_id, event_ref, coach_user_id, reason, overridden_by)
             VALUES (:organization_id, :event, :coach, :reason, :by) RETURNING id',
        );
        $stmt->execute($this->scopeParams($organization, ['event' => $eventRef, 'coach' => $coachUserId, 'reason' => $reason, 'by' => $overriddenBy]));

        return (string) $stmt->fetchColumn();
    }

    public function create(
        OrganizationId $organization,
        string $eventRef,
        DateTimeImmutable $eventDate,
        TimeRange $window,
        string $coachUserId,
        string $assignedBy,
        ?string $overrideId,
    ): CoachAssignment {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO coach_assignments (organization_id, event_ref, event_date, starts_at, ends_at, coach_user_id, assigned_by, override_id)
                 VALUES (:organization_id, :event, :date, :starts, :ends, :coach, :by, :override) RETURNING id',
            );
            $stmt->execute($this->scopeParams($organization, [
                'event' => $eventRef,
                'date' => $eventDate->format('Y-m-d'),
                'starts' => $window->startLabel() . ':00',
                'ends' => $window->endLabel() . ':00',
                'coach' => $coachUserId,
                'by' => $assignedBy,
                'override' => $overrideId,
            ]));
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                throw new AssignmentAlreadyExists('The Coach is already assigned to this event.', 0, $e);
            }

            throw $e;
        }

        return $this->find($organization, (string) $stmt->fetchColumn()) ?? throw new \LogicException('The new assignment vanished.');
    }

    public function find(OrganizationId $organization, string $id): ?CoachAssignment
    {
        if (Uuid::tryFromString($id) === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(self::SELECT . ' AND a.id = :id');
        $stmt->execute($this->scopeParams($organization, ['id' => $id]));
        $row = $stmt->fetch();

        return is_array($row) ? $this->from($row) : null;
    }

    public function forOrganization(OrganizationId $organization): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' ORDER BY a.event_date DESC, a.starts_at DESC, a.id');
        $stmt->execute($this->scopeParams($organization));

        return array_values(array_map($this->from(...), $stmt->fetchAll()));
    }

    public function forCoach(OrganizationId $organization, string $coachUserId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' AND a.coach_user_id = :coach ORDER BY a.event_date, a.starts_at, a.id');
        $stmt->execute($this->scopeParams($organization, ['coach' => $coachUserId]));

        return array_values(array_map($this->from(...), $stmt->fetchAll()));
    }

    public function respond(OrganizationId $organization, string $id, AssignmentStatus $status, ?string $note, DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE coach_assignments SET status = :status, change_note = :note, responded_at = :at WHERE id = :id AND ' . $this->scope(),
        );
        $stmt->execute($this->scopeParams($organization, ['status' => $status->value, 'note' => $note, 'at' => $at->format('Y-m-d H:i:s.uP'), 'id' => $id]));
    }

    /** @param array<string, mixed> $row */
    private function from(array $row): CoachAssignment
    {
        return new CoachAssignment(
            (string) $row['id'],
            OrganizationId::fromString((string) $row['organization_id']),
            (string) $row['event_ref'],
            new DateTimeImmutable((string) $row['event_date']),
            TimeRange::fromStrings((string) $row['starts_at'], (string) $row['ends_at']),
            (string) $row['coach_user_id'],
            (string) $row['coach_name'],
            AssignmentStatus::from((string) $row['status']),
            $row['override_id'] === null ? null : (string) $row['override_id'],
            $row['override_reason'] === null ? null : (string) $row['override_reason'],
            $row['change_note'] === null ? null : (string) $row['change_note'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
