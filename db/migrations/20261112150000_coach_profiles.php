<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Role-specific profile data (US-01.11): the Coach's public profile, per-account extras (photo, a
 * parent's emergency contact, the Super Admin's settings), and the player fields the profile form edits.
 * Expand-only: new tables and nullable columns, so reverting needs no data backfill.
 */
final class CoachProfiles extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE coach_profiles (
                user_id uuid PRIMARY KEY REFERENCES users (id) ON DELETE RESTRICT,
                bio text,
                credentials text,
                certifications jsonb NOT NULL DEFAULT '[]'::jsonb,
                is_public boolean NOT NULL DEFAULT false,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT coach_profiles_certifications_array CHECK (jsonb_typeof(certifications) = 'array')
            )
            SQL);
        $this->execute(<<<'SQL'
            CREATE TABLE user_profiles (
                user_id uuid PRIMARY KEY REFERENCES users (id) ON DELETE RESTRICT,
                photo_path text,
                emergency_contact text,
                admin_settings text,
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('ALTER TABLE player_profiles ADD COLUMN jersey_number smallint');
        $this->execute('ALTER TABLE player_profiles ADD COLUMN skill_level text');
        $this->execute('ALTER TABLE player_profiles ADD CONSTRAINT player_profiles_jersey_number_range CHECK (jersey_number IS NULL OR jersey_number BETWEEN 0 AND 999)');
        $this->execute("ALTER TABLE player_profiles ADD CONSTRAINT player_profiles_skill_level_check CHECK (skill_level IS NULL OR skill_level IN ('beginner', 'intermediate', 'advanced', 'elite'))");
        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON coach_profiles, user_profiles TO app_rw');
    }

    public function down(): void
    {
        $this->table('coach_profiles')->drop()->save();
        $this->table('user_profiles')->drop()->save();
        $this->table('player_profiles')->removeColumn('jersey_number')->removeColumn('skill_level')->save();
    }
}
