<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Impersonation log (design C12) and the one relaxation anonymization needs: a deleted player's birth
 * date is removed, so the column accepts NULL. The impersonation rows are never deleted by the
 * application (no DELETE grant); every action taken during one is in audit_log. Expand-only.
 */
final class Impersonations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE impersonations (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                admin_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                target_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                started_at timestamptz NOT NULL,
                last_activity_at timestamptz NOT NULL,
                ended_at timestamptz,
                end_reason text,
                CONSTRAINT impersonations_end_reason_check CHECK (end_reason IS NULL OR end_reason IN ('manual', 'timeout', 'browser_closed', 'logout', 'session_expired', 'target_unavailable')),
                CONSTRAINT impersonations_end_pair CHECK ((ended_at IS NULL) = (end_reason IS NULL)),
                CONSTRAINT impersonations_end_after_start CHECK (ended_at IS NULL OR ended_at >= started_at),
                CONSTRAINT impersonations_not_self CHECK (admin_user_id <> target_user_id)
            )
            SQL);
        $this->execute('CREATE INDEX impersonations_admin_started ON impersonations (admin_user_id, started_at)');
        $this->execute('CREATE INDEX impersonations_target ON impersonations (target_user_id)');
        $this->execute('CREATE INDEX impersonations_started ON impersonations (started_at DESC, id)');
        $this->execute('CREATE INDEX impersonations_open ON impersonations (started_at) WHERE ended_at IS NULL');
        $this->execute('CREATE INDEX sessions_impersonation ON sessions (impersonation_id) WHERE impersonation_id IS NOT NULL');
        $this->execute('GRANT SELECT, INSERT, UPDATE ON impersonations TO app_rw');

        $this->execute('ALTER TABLE player_profiles ALTER COLUMN birth_date DROP NOT NULL');
    }

    public function down(): void
    {
        $this->execute("UPDATE player_profiles SET birth_date = DATE '1900-01-01' WHERE birth_date IS NULL");
        $this->execute('ALTER TABLE player_profiles ALTER COLUMN birth_date SET NOT NULL');
        $this->execute('DROP INDEX sessions_impersonation');
        $this->table('impersonations')->drop()->save();
    }
}
