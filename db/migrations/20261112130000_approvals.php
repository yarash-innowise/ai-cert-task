<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Parent approval requests for a child login's purchases, token spends and RSVPs (design C8). The
 * status machine is checked by the conditional UPDATEs of the use cases; the CHECKs only keep the
 * data well-formed. `rule_snapshot` freezes the rules in force when the child asked (a later change of
 * "Allow token spending without approval" never alters a pending request). Expand-only.
 */
final class Approvals extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE approval_requests (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                child_profile_id uuid NOT NULL REFERENCES player_profiles (id) ON DELETE RESTRICT,
                child_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                parent_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                kind text NOT NULL,
                event_ref uuid NOT NULL,
                event_title text,
                amount_minor bigint NOT NULL DEFAULT 0,
                currency text NOT NULL DEFAULT 'USD',
                payment_type text NOT NULL,
                status text NOT NULL DEFAULT 'pending',
                rule_snapshot jsonb NOT NULL DEFAULT '{}'::jsonb,
                child_note text,
                parent_note text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                expires_at timestamptz NOT NULL,
                decided_at timestamptz,
                decided_by uuid REFERENCES users (id) ON DELETE RESTRICT,
                handoff_at timestamptz,
                version integer NOT NULL DEFAULT 1,
                CONSTRAINT approval_requests_kind_check CHECK (kind IN ('purchase', 'token_spend', 'rsvp', 'rsvp_cancel')),
                CONSTRAINT approval_requests_payment_type_check CHECK (payment_type IN ('usd', 'tokens', 'none')),
                CONSTRAINT approval_requests_status_check CHECK (status IN ('pending', 'info_requested', 'approved', 'denied', 'expired', 'auto_denied', 'completed', 'payment_failed')),
                CONSTRAINT approval_requests_amount_check CHECK (amount_minor >= 0),
                CONSTRAINT approval_requests_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT approval_requests_expiry_after_creation CHECK (expires_at > created_at)
            )
            SQL);
        $this->execute('CREATE INDEX approval_requests_parent_status ON approval_requests (parent_user_id, status)');
        $this->execute('CREATE INDEX approval_requests_child ON approval_requests (child_user_id, created_at DESC)');
        $this->execute('CREATE INDEX approval_requests_organization ON approval_requests (organization_id)');
        $this->execute("CREATE INDEX approval_requests_pending_expiry ON approval_requests (expires_at) WHERE status IN ('pending', 'info_requested')");
        $this->execute("CREATE INDEX approval_requests_awaiting_handoff ON approval_requests (decided_at) WHERE status = 'approved'");
        // A child's double click must not create two requests for the same thing while one is still open.
        $this->execute("CREATE UNIQUE INDEX approval_requests_one_open_per_child_event_kind ON approval_requests (child_profile_id, event_ref, kind) WHERE status IN ('pending', 'info_requested', 'approved')");
        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON approval_requests TO app_rw');
    }

    public function down(): void
    {
        $this->table('approval_requests')->drop()->save();
    }
}
