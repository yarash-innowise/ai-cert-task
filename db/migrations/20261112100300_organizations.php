<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** A Trainer's organization (the tenant). One organization per Trainer user. */
final class Organizations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE organizations (
                id uuid PRIMARY KEY DEFAULT uuidv7(),
                owner_user_id uuid NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                business_name text NOT NULL,
                status text NOT NULL DEFAULT 'active',
                logo_path text,
                brand_color text,
                timezone text NOT NULL DEFAULT 'UTC',
                details text,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT organizations_owner_unique UNIQUE (owner_user_id),
                CONSTRAINT organizations_status_check CHECK (status IN ('active', 'inactive')),
                CONSTRAINT organizations_brand_color_check CHECK (brand_color IS NULL OR brand_color ~ '^#[0-9A-Fa-f]{6}$'),
                CONSTRAINT organizations_business_name_present CHECK (length(trim(business_name)) > 0)
            )
            SQL);
        $this->execute('GRANT SELECT, INSERT, UPDATE, DELETE ON organizations TO app_rw');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE organizations');
    }
}
