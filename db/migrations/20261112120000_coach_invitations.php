<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Coach invitations (single-use link, 7 days) and coach memberships. A Coach is active under at most
 * one Trainer at any time: the partial unique index makes that a database invariant. Expand-only.
 */
final class CoachInvitations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE coach_invitations (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                email text NOT NULL,
                name text,
                message text,
                token_hash text NOT NULL,
                created_by uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                expires_at timestamptz NOT NULL,
                accepted_at timestamptz,
                accepted_by uuid REFERENCES users (id) ON DELETE RESTRICT,
                revoked_at timestamptz,
                CONSTRAINT coach_invitations_token_unique UNIQUE (token_hash),
                CONSTRAINT coach_invitations_email_lowercase CHECK (email = lower(email)),
                CONSTRAINT coach_invitations_expiry_after_creation CHECK (expires_at > created_at)
            )
            SQL);
        $this->execute('CREATE INDEX coach_invitations_organization ON coach_invitations (organization_id, created_at DESC)');
        $this->execute('CREATE INDEX coach_invitations_email ON coach_invitations (email)');

        $this->execute(<<<'SQL'
            CREATE TABLE coach_memberships (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                coach_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                invitation_id uuid REFERENCES coach_invitations (id) ON DELETE RESTRICT,
                status text NOT NULL DEFAULT 'active',
                started_at timestamptz NOT NULL DEFAULT now(),
                ended_at timestamptz,
                CONSTRAINT coach_memberships_status_check CHECK (status IN ('active', 'ended'))
            )
            SQL);
        $this->execute("CREATE UNIQUE INDEX coach_memberships_one_active_per_coach ON coach_memberships (coach_user_id) WHERE status = 'active'");
        $this->execute('CREATE INDEX coach_memberships_organization ON coach_memberships (organization_id)');
        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_invitations, coach_memberships TO app_rw');
    }

    public function down(): void
    {
        $this->table('coach_memberships')->drop()->save();
        $this->table('coach_invitations')->drop()->save();
    }
}
