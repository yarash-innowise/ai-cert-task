<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class IdentityCore extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE users (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                email text NOT NULL,
                password_hash text,
                name text NOT NULL,
                phone text,
                role text NOT NULL,
                status text NOT NULL DEFAULT 'active',
                account_kind text NOT NULL DEFAULT 'standard',
                guardian_user_id uuid REFERENCES users (id) ON DELETE RESTRICT,
                must_set_password boolean NOT NULL DEFAULT false,
                email_verified_at timestamptz,
                version integer NOT NULL DEFAULT 1,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT users_email_unique UNIQUE (email),
                CONSTRAINT users_email_lowercase CHECK (email = lower(email)),
                CONSTRAINT users_role_check CHECK (role IN ('super_admin', 'trainer', 'coach', 'player')),
                CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive', 'deleted')),
                CONSTRAINT users_account_kind_check CHECK (account_kind IN ('standard', 'child')),
                CONSTRAINT users_child_has_guardian CHECK (account_kind <> 'child' OR guardian_user_id IS NOT NULL)
            )
            SQL);
        $this->execute('CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops)');
        $this->execute('CREATE INDEX users_email_trgm ON users USING gin (email gin_trgm_ops)');
        $this->execute('CREATE INDEX users_role_status_created ON users (role, status, created_at DESC)');
        $this->execute('CREATE INDEX users_guardian ON users (guardian_user_id) WHERE guardian_user_id IS NOT NULL');

        $this->execute(<<<'SQL'
            CREATE TABLE sessions (
                token_hash text PRIMARY KEY,
                user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                csrf_token text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                last_seen_at timestamptz NOT NULL DEFAULT now(),
                active_context jsonb,
                impersonation_id uuid,
                impersonator_user_id uuid REFERENCES users (id) ON DELETE RESTRICT
            )
            SQL);
        $this->execute('CREATE INDEX sessions_user ON sessions (user_id)');
        $this->execute('CREATE INDEX sessions_last_seen ON sessions (last_seen_at)');

        $this->execute(<<<'SQL'
            CREATE TABLE login_attempts (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                email text NOT NULL,
                ip text NOT NULL,
                succeeded boolean NOT NULL DEFAULT false,
                attempted_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX login_attempts_email ON login_attempts (email, attempted_at)');
        $this->execute('CREATE INDEX login_attempts_ip ON login_attempts (ip, attempted_at)');

        $this->execute(<<<'SQL'
            CREATE TABLE auth_tokens (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                purpose text NOT NULL,
                token_hash text NOT NULL,
                expires_at timestamptz NOT NULL,
                consumed_at timestamptz,
                revoked_at timestamptz,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT auth_tokens_hash_unique UNIQUE (token_hash),
                CONSTRAINT auth_tokens_purpose_check CHECK (purpose IN ('password_reset', 'email_verification', 'trainer_setup'))
            )
            SQL);
        $this->execute('CREATE INDEX auth_tokens_user_purpose ON auth_tokens (user_id, purpose)');

        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON users, sessions, login_attempts, auth_tokens TO app_rw');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE auth_tokens');
        $this->execute('DROP TABLE login_attempts');
        $this->execute('DROP TABLE sessions');
        $this->execute('DROP TABLE users');
    }
}
