<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Static player ShareLinks (one active per organization) and the record of what each link produced. Expand-only. */
final class ShareLinks extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE share_links (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                kind text NOT NULL DEFAULT 'player',
                code text NOT NULL,
                active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                deactivated_at timestamptz,
                use_count integer NOT NULL DEFAULT 0,
                last_used_at timestamptz,
                CONSTRAINT share_links_code_unique UNIQUE (code),
                CONSTRAINT share_links_kind_check CHECK (kind IN ('player')),
                CONSTRAINT share_links_code_format CHECK (code ~ '^[0-9A-Za-z]{22}$')
            )
            SQL);
        $this->execute('CREATE UNIQUE INDEX share_links_one_active_per_organization ON share_links (organization_id, kind) WHERE active');
        $this->execute('CREATE INDEX share_links_organization ON share_links (organization_id)');

        $this->execute(<<<'SQL'
            CREATE TABLE share_link_uses (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                share_link_id uuid NOT NULL REFERENCES share_links (id) ON DELETE RESTRICT,
                user_id uuid REFERENCES users (id) ON DELETE RESTRICT,
                player_profile_id uuid REFERENCES player_profiles (id) ON DELETE RESTRICT,
                outcome text NOT NULL,
                used_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX share_link_uses_link_time ON share_link_uses (share_link_id, used_at)');

        $this->execute('ALTER TABLE player_trainer_associations ADD CONSTRAINT player_trainer_source_link FOREIGN KEY (source_share_link_id) REFERENCES share_links (id) ON DELETE RESTRICT');
        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON share_links, share_link_uses TO app_rw');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE player_trainer_associations DROP CONSTRAINT player_trainer_source_link');
        $this->table('share_link_uses')->drop()->save();
        $this->table('share_links')->drop()->save();
    }
}
