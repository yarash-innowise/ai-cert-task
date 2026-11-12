<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Deleting a user must be able to remove the free text they authored (AC-76, review F-02). Availability
 * overrides stay insert-only for the application role (no row UPDATE, no DELETE); only the `reason` column may
 * be rewritten, and only the user-deletion path does that ("[removed]"). Nothing else is granted.
 */
final class OverrideReasonScrubGrant extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('GRANT UPDATE (reason) ON coach_availability_overrides TO app_rw');
    }

    public function down(): void
    {
        $this->execute('REVOKE UPDATE (reason) ON coach_availability_overrides FROM app_rw');
    }
}
