<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Weekly availability of a player profile or a Coach (design C10). Times are wall-clock in the
 * organization's time zone, weekday 0 = Monday .. 6 = Sunday. No rows for a subject means "no
 * preference given". A day listed in availability_unavailable_days is an explicit "Not Available".
 * Overlapping slots of one subject on one weekday are refused by the database itself. Expand-only.
 */
final class Availability extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE availability_slots (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                subject_type text NOT NULL,
                subject_id uuid NOT NULL,
                weekday smallint NOT NULL,
                starts_at time(0) NOT NULL,
                ends_at time(0) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT availability_slots_subject_check CHECK (subject_type IN ('player_profile', 'coach')),
                CONSTRAINT availability_slots_weekday_check CHECK (weekday BETWEEN 0 AND 6),
                CONSTRAINT availability_slots_end_after_start CHECK (ends_at > starts_at),
                CONSTRAINT availability_slots_no_overlap EXCLUDE USING gist (
                    subject_type WITH =,
                    subject_id WITH =,
                    weekday WITH =,
                    tsrange(timestamp '2000-01-01' + starts_at, timestamp '2000-01-01' + ends_at) WITH &&
                )
            )
            SQL);
        $this->execute('CREATE INDEX availability_slots_subject ON availability_slots (subject_type, subject_id)');

        $this->execute(<<<'SQL'
            CREATE TABLE availability_unavailable_days (
                subject_type text NOT NULL,
                subject_id uuid NOT NULL,
                weekday smallint NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (subject_type, subject_id, weekday),
                CONSTRAINT availability_unavailable_subject_check CHECK (subject_type IN ('player_profile', 'coach')),
                CONSTRAINT availability_unavailable_weekday_check CHECK (weekday BETWEEN 0 AND 6)
            )
            SQL);

        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON availability_slots, availability_unavailable_days TO app_rw');
    }

    public function down(): void
    {
        $this->table('availability_unavailable_days')->drop()->save();
        $this->table('availability_slots')->drop()->save();
    }
}
