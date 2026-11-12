<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Extensions and the restricted application role `app_rw`.
 * The role password comes from APP_RW_PASSWORD (never hard-coded).
 */
final class RolesExtensions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->execute('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $password = getenv('APP_RW_PASSWORD');
        if ($password === false || $password === '') {
            throw new RuntimeException('APP_RW_PASSWORD must be set to create the app_rw role.');
        }
        $quoted = $this->quoteLiteral($password);

        $this->execute(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_rw') THEN
                    CREATE ROLE app_rw LOGIN PASSWORD {$quoted};
                ELSE
                    ALTER ROLE app_rw LOGIN PASSWORD {$quoted};
                END IF;
            END
            \$\$
            SQL);
        $this->execute('GRANT CONNECT ON DATABASE ' . $this->quoteIdent($this->currentDatabase()) . ' TO app_rw');
        $this->execute('GRANT USAGE ON SCHEMA public TO app_rw');
    }

    public function down(): void
    {
        $this->execute('REVOKE USAGE ON SCHEMA public FROM app_rw');
        $this->execute('REVOKE CONNECT ON DATABASE ' . $this->quoteIdent($this->currentDatabase()) . ' FROM app_rw');
        // The role is cluster-wide (shared by the dev and test databases) and is intentionally kept.
    }

    private function currentDatabase(): string
    {
        $row = $this->fetchRow('SELECT current_database() AS db');
        if (!is_array($row)) {
            throw new RuntimeException('Cannot determine current database.');
        }

        return (string) $row['db'];
    }

    private function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
