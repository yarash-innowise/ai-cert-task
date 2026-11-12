<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * What an anonymization left to clean up after the transaction commits: orphaned stored files, and the player
 * profiles whose weekly availability has to be erased (that data belongs to the Availability module, which does
 * the erasure when it handles the queued job).
 */
final readonly class AnonymizationResult
{
    /**
     * @param list<string> $photos
     * @param list<string> $logos
     * @param list<string> $playerProfileIds
     */
    public function __construct(
        public array $photos = [],
        public array $logos = [],
        public array $playerProfileIds = [],
    ) {}
}
