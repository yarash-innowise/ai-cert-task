<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Player profiles ("Me" and children) and their associations with Trainers (organizations). Expand-only. */
final class Family extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE player_profiles (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                owner_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                kind text NOT NULL,
                login_user_id uuid REFERENCES users (id) ON DELETE RESTRICT,
                first_name text NOT NULL,
                last_name text NOT NULL,
                birth_date date NOT NULL,
                gender text,
                school text,
                photo_path text,
                token_spend_without_approval boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                deleted_at timestamptz,
                CONSTRAINT player_profiles_kind_check CHECK (kind IN ('self', 'child')),
                CONSTRAINT player_profiles_gender_check CHECK (gender IS NULL OR gender IN ('female', 'male', 'other')),
                CONSTRAINT player_profiles_child_gender_required CHECK (kind <> 'child' OR gender IS NOT NULL),
                CONSTRAINT player_profiles_first_name_present CHECK (length(trim(first_name)) > 0),
                CONSTRAINT player_profiles_login_unique UNIQUE (login_user_id)
            )
            SQL);
        $this->execute("CREATE UNIQUE INDEX player_profiles_one_self_per_owner ON player_profiles (owner_user_id) WHERE kind = 'self'");
        $this->execute('CREATE INDEX player_profiles_owner ON player_profiles (owner_user_id)');

        $this->execute(<<<'SQL'
            CREATE TABLE player_trainer_associations (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                player_profile_id uuid NOT NULL REFERENCES player_profiles (id) ON DELETE RESTRICT,
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                source_share_link_id uuid,
                associated_at timestamptz NOT NULL DEFAULT now(),
                ended_at timestamptz,
                ended_by uuid REFERENCES users (id) ON DELETE RESTRICT,
                end_reason text
            )
            SQL);
        $this->execute('CREATE UNIQUE INDEX player_trainer_active_pair ON player_trainer_associations (player_profile_id, organization_id) WHERE ended_at IS NULL');
        $this->execute('CREATE INDEX player_trainer_org_active ON player_trainer_associations (organization_id) WHERE ended_at IS NULL');
        $this->execute('CREATE INDEX player_trainer_profile ON player_trainer_associations (player_profile_id)');

        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON player_profiles, player_trainer_associations TO app_rw');
    }

    public function down(): void
    {
        $this->table('player_trainer_associations')->drop()->save();
        $this->table('player_profiles')->drop()->save();
    }
}
