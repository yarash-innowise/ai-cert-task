<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * A module's answer to "in which organizations may this user act right now?". Sources are queried
 * on every request, so a revoked membership stops working immediately. Modules add their own source
 * to the `context.sources` container list (Organization: owned org; later Coach and Family).
 */
interface ContextSource
{
    /**
     * @return list<ContextOption> valid options, most relevant first
     */
    public function optionsFor(string $userId, string $role): array;
}
