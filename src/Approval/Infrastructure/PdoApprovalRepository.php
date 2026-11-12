<?php

declare(strict_types=1);

namespace App\Approval\Infrastructure;

use App\Approval\Domain\ApprovalKind;
use App\Approval\Domain\ApprovalRepository;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\ApprovalStatus;
use App\Approval\Domain\NewApprovalRequest;
use App\Approval\Domain\OpenRequestExists;
use App\Approval\Domain\PaymentType;
use App\Shared\Domain\Uuid;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Approval requests are reached by their participants (the child login, the parent), never by an
 * organization, so the access path is the user id; a Trainer has no access to them in this epic.
 */
final class PdoApprovalRepository implements ApprovalRepository
{
    private const TS = 'Y-m-d H:i:s.uP';

    public function __construct(private readonly PDO $pdo) {}

    public function create(NewApprovalRequest $new): ApprovalRequest
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO approval_requests
                    (organization_id, child_profile_id, child_user_id, parent_user_id, kind, event_ref, event_title, amount_minor, currency,
                     payment_type, rule_snapshot, child_note, created_at, updated_at, expires_at)
                 VALUES (:organization, :profile, :child, :parent, :kind, :event, :title, :amount, :currency,
                     :payment, :snapshot, :note, :created, :created, :expires) RETURNING *',
            );
            $stmt->execute([
                'organization' => $new->organizationId,
                'profile' => $new->childProfileId,
                'child' => $new->childUserId,
                'parent' => $new->parentUserId,
                'kind' => $new->kind->value,
                'event' => $new->eventRef,
                'title' => $new->eventTitle,
                'amount' => $new->amountMinor,
                'currency' => $new->currency,
                'payment' => $new->paymentType->value,
                'snapshot' => json_encode($new->ruleSnapshot, JSON_THROW_ON_ERROR),
                'note' => $new->childNote,
                'created' => $new->createdAt->format(self::TS),
                'expires' => $new->expiresAt->format(self::TS),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'approval_requests_one_open_per_child_event_kind')) {
                throw new OpenRequestExists('The child already has an open request for this.', 0, $e);
            }
            throw $e;
        }
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    public function findOpen(string $childProfileId, string $eventRef, ApprovalKind $kind): ?ApprovalRequest
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM approval_requests
             WHERE child_profile_id = :profile AND event_ref = :event AND kind = :kind AND status IN ('pending', 'info_requested', 'approved')",
        );
        $stmt->execute(['profile' => $childProfileId, 'event' => $eventRef, 'kind' => $kind->value]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(string $id): ?ApprovalRequest
    {
        if (Uuid::tryFromString($id) === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM approval_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function forChild(string $childUserId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM approval_requests WHERE child_user_id = :child ORDER BY created_at DESC, id DESC');
        $stmt->execute(['child' => $childUserId]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll()));
    }

    public function forParent(string $parentUserId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM approval_requests WHERE parent_user_id = :parent
             ORDER BY (status IN ('pending', 'info_requested')) DESC, created_at DESC, id DESC",
        );
        $stmt->execute(['parent' => $parentUserId]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll()));
    }

    public function decide(string $id, string $parentUserId, ApprovalStatus $to, ?string $note, DateTimeImmutable $now): ?ApprovalRequest
    {
        if (Uuid::tryFromString($id) === null) {
            return null;
        }
        $final = $to === ApprovalStatus::Approved || $to === ApprovalStatus::Denied;
        $stmt = $this->pdo->prepare(
            "UPDATE approval_requests
             SET status = :to, parent_note = :note, updated_at = :now, version = version + 1,
                 decided_at = CASE WHEN :final THEN CAST(:now AS timestamptz) ELSE decided_at END,
                 decided_by = CASE WHEN :final THEN CAST(:parent AS uuid) ELSE decided_by END
             WHERE id = :id AND parent_user_id = :parent AND status IN ('pending', 'info_requested') AND expires_at > :now
             RETURNING *",
        );
        $stmt->bindValue('to', $to->value);
        $stmt->bindValue('note', $note);
        $stmt->bindValue('now', $now->format(self::TS));
        $stmt->bindValue('final', $final, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id);
        $stmt->bindValue('parent', $parentUserId);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function expireDue(DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE approval_requests SET status = 'expired', updated_at = :now, version = version + 1
             WHERE status IN ('pending', 'info_requested') AND expires_at <= :now
             RETURNING *",
        );
        $stmt->execute(['now' => $now->format(self::TS)]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll()));
    }

    public function autoDenyForParent(string $parentUserId, DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE approval_requests SET status = 'auto_denied', decided_at = :now, updated_at = :now, version = version + 1
             WHERE parent_user_id = :parent AND status IN ('pending', 'info_requested')
             RETURNING *",
        );
        $stmt->execute(['parent' => $parentUserId, 'now' => $now->format(self::TS)]);

        return array_values(array_map($this->hydrate(...), $stmt->fetchAll()));
    }

    public function finishHandoff(string $id, ApprovalStatus $to, DateTimeImmutable $now): ?ApprovalRequest
    {
        if (!in_array($to, [ApprovalStatus::Completed, ApprovalStatus::PaymentFailed], true)) {
            throw new \InvalidArgumentException('A hand-off ends as completed or payment_failed.');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE approval_requests SET status = :to, handoff_at = :now, updated_at = :now, version = version + 1
             WHERE id = :id AND status = 'approved'
             RETURNING *",
        );
        $stmt->execute(['to' => $to->value, 'now' => $now->format(self::TS), 'id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ApprovalRequest
    {
        $snapshot = json_decode((string) $row['rule_snapshot'], true);

        return new ApprovalRequest(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['child_profile_id'],
            (string) $row['child_user_id'],
            (string) $row['parent_user_id'],
            ApprovalKind::from((string) $row['kind']),
            (string) $row['event_ref'],
            is_string($row['event_title']) ? $row['event_title'] : null,
            (int) $row['amount_minor'],
            (string) $row['currency'],
            PaymentType::from((string) $row['payment_type']),
            ApprovalStatus::from((string) $row['status']),
            is_array($snapshot) ? $snapshot : [],
            is_string($row['child_note']) ? $row['child_note'] : null,
            is_string($row['parent_note']) ? $row['parent_note'] : null,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            is_string($row['decided_at']) ? new DateTimeImmutable($row['decided_at']) : null,
            (int) $row['version'],
        );
    }
}
