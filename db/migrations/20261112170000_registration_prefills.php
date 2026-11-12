<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Camp-form pre-fill records (AC-85): the token is stored only as its SHA-256, the data is what the person typed elsewhere. Expand-only. */
final class RegistrationPrefills extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE registration_prefills (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                organization_id uuid NOT NULL REFERENCES organizations (id) ON DELETE RESTRICT,
                token_hash text NOT NULL,
                data jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at timestamptz NOT NULL DEFAULT now(),
                expires_at timestamptz NOT NULL,
                consumed_at timestamptz,
                CONSTRAINT registration_prefills_token_unique UNIQUE (token_hash),
                CONSTRAINT registration_prefills_token_format CHECK (token_hash ~ '^[0-9a-f]{64}$')
            )
            SQL);
        $this->execute('CREATE INDEX registration_prefills_organization ON registration_prefills (organization_id)');
        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON registration_prefills TO app_rw');
    }

    public function down(): void
    {
        $this->table('registration_prefills')->drop()->save();
    }
}
