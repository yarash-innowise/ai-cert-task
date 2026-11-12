<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A Trainer assigns a Coach to an event (design C10). `event_ref` is a plain reference with no foreign
 * key: events belong to Epic-02. An assignment outside the Coach's schedule needs an override whose
 * reason is not blank; overrides are insert-only (no UPDATE/DELETE grant). Expand-only.
 */
final class CoachAssignments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE coach_availability_overrides (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                event_ref text NOT NULL,
                coach_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                reason text NOT NULL,
                overridden_by uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT coach_overrides_reason_present CHECK (length(trim(reason)) > 0),
                CONSTRAINT coach_overrides_event_ref_present CHECK (length(trim(event_ref)) > 0)
            )
            SQL);
        $this->execute('CREATE INDEX coach_overrides_event ON coach_availability_overrides (organization_id, event_ref)');

        $this->execute(<<<'SQL'
            CREATE TABLE coach_assignments (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                event_ref text NOT NULL,
                event_date date NOT NULL,
                starts_at time(0) NOT NULL,
                ends_at time(0) NOT NULL,
                coach_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                status text NOT NULL DEFAULT 'assigned',
                override_id uuid REFERENCES coach_availability_overrides (id) ON DELETE RESTRICT,
                assigned_by uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                change_note text,
                created_at timestamptz NOT NULL DEFAULT now(),
                responded_at timestamptz,
                CONSTRAINT coach_assignments_status_check CHECK (status IN ('assigned', 'accepted', 'change_requested')),
                CONSTRAINT coach_assignments_event_ref_present CHECK (length(trim(event_ref)) > 0),
                CONSTRAINT coach_assignments_end_after_start CHECK (ends_at > starts_at),
                CONSTRAINT coach_assignments_unique_coach_event UNIQUE (organization_id, event_ref, coach_user_id)
            )
            SQL);
        $this->execute('CREATE INDEX coach_assignments_event ON coach_assignments (organization_id, event_ref)');
        $this->execute('CREATE INDEX coach_assignments_coach ON coach_assignments (coach_user_id, event_date)');

        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_assignments TO app_rw');
        $this->execute('GRANT SELECT, INSERT ON coach_availability_overrides TO app_rw');
    }

    public function down(): void
    {
        $this->table('coach_assignments')->drop()->save();
        $this->table('coach_availability_overrides')->drop()->save();
    }
}
