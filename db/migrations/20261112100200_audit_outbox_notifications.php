<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Append-only audit log (grants + trigger), transactional outbox, in-app notifications.
 */
final class AuditOutboxNotifications extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE audit_log (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                occurred_at timestamptz NOT NULL DEFAULT now(),
                actor_user_id uuid REFERENCES users (id) ON DELETE RESTRICT,
                impersonator_user_id uuid REFERENCES users (id) ON DELETE RESTRICT,
                action text NOT NULL,
                subject_type text NOT NULL,
                subject_id text,
                organization_id uuid,
                payload jsonb NOT NULL DEFAULT '{}'::jsonb,
                ip text
            )
            SQL);
        $this->execute('CREATE INDEX audit_log_action_time ON audit_log (action, occurred_at)');
        $this->execute('CREATE INDEX audit_log_subject ON audit_log (subject_type, subject_id)');

        $this->execute(<<<'SQL'
            CREATE FUNCTION audit_log_reject_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only (% not allowed)', TG_OP
                    USING ERRCODE = 'integrity_constraint_violation';
            END
            $$
            SQL);
        $this->execute(<<<'SQL'
            CREATE TRIGGER audit_log_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_reject_change()
            SQL);
        $this->execute(<<<'SQL'
            CREATE TRIGGER audit_log_no_truncate
                BEFORE TRUNCATE ON audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION audit_log_reject_change()
            SQL);
        $this->execute('REVOKE ALL ON audit_log FROM PUBLIC');
        $this->execute('GRANT INSERT, SELECT ON audit_log TO app_rw');

        $this->execute(<<<'SQL'
            CREATE TABLE outbox (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                kind text NOT NULL,
                topic text NOT NULL,
                payload jsonb NOT NULL DEFAULT '{}'::jsonb,
                status text NOT NULL DEFAULT 'pending',
                attempts integer NOT NULL DEFAULT 0,
                available_at timestamptz NOT NULL DEFAULT now(),
                last_error text,
                created_at timestamptz NOT NULL DEFAULT now(),
                processed_at timestamptz,
                CONSTRAINT outbox_kind_check CHECK (kind IN ('email', 'job')),
                CONSTRAINT outbox_status_check CHECK (status IN ('pending', 'done', 'failed'))
            )
            SQL);
        $this->execute("CREATE INDEX outbox_pending ON outbox (available_at) WHERE status = 'pending'");

        $this->execute(<<<'SQL'
            CREATE TABLE notifications (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                kind text NOT NULL,
                title text NOT NULL,
                body text NOT NULL DEFAULT '',
                payload jsonb NOT NULL DEFAULT '{}'::jsonb,
                read_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX notifications_user_read ON notifications (user_id, read_at)');

        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON outbox, notifications TO app_rw');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE notifications');
        $this->execute('DROP TABLE outbox');
        $this->execute('DROP TABLE audit_log');
        $this->execute('DROP FUNCTION audit_log_reject_change()');
    }
}
