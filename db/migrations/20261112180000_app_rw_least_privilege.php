<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Least privilege for the application role (AC-87, review F-01). The web app and the worker connect
 * as `app_rw`; the owner role is used by migrations only. Earlier migrations grant per table; this one
 * closes the gaps: no TRUNCATE/REFERENCES/TRIGGER anywhere, no schema CREATE, no access to the
 * migration log, an append-only audit log, and no automatic table access for tables added later
 * (every new migration must grant explicitly, as the existing ones do).
 */
final class AppRwLeastPrivilege extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('REVOKE CREATE ON SCHEMA public FROM app_rw');
        $this->execute('REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM app_rw');
        $this->execute('REVOKE ALL ON phinxlog FROM app_rw');
        $this->execute('REVOKE ALL ON audit_log FROM app_rw');
        $this->execute('GRANT SELECT, INSERT ON audit_log TO app_rw');
        $this->execute('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_rw');
        $this->execute('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_rw');
    }

    public function down(): void
    {
        $this->execute('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM app_rw');
        $this->execute('REVOKE USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public FROM app_rw');
    }
}
