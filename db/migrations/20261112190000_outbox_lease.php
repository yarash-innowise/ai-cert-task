<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Outbox claim lease (review F-10): the worker marks a row `processing` with a lease in a short
 * transaction and handles it with no transaction open; an expired lease makes the row claimable again.
 * app_rw already holds table-level SELECT/INSERT/UPDATE/DELETE on outbox (which covers the new column and
 * the retention purge, F-05); nothing else is granted here.
 */
final class OutboxLease extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE outbox ADD COLUMN locked_until timestamptz');
        $this->execute('ALTER TABLE outbox DROP CONSTRAINT outbox_status_check');
        $this->execute("ALTER TABLE outbox ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending', 'processing', 'done', 'failed'))");
        $this->execute("CREATE INDEX outbox_processing ON outbox (locked_until) WHERE status = 'processing'");
        $this->execute("CREATE INDEX outbox_retention ON outbox (processed_at) WHERE status IN ('done', 'failed')");
    }

    public function down(): void
    {
        $this->execute('DROP INDEX outbox_retention');
        $this->execute('DROP INDEX outbox_processing');
        $this->execute("UPDATE outbox SET status = 'pending' WHERE status = 'processing'");
        $this->execute('ALTER TABLE outbox DROP CONSTRAINT outbox_status_check');
        $this->execute("ALTER TABLE outbox ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending', 'done', 'failed'))");
        $this->execute('ALTER TABLE outbox DROP COLUMN locked_until');
    }
}
