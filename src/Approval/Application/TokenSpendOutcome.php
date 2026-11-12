<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRequest;
use App\Integration\Application\SpendResult;

/** What happened to a child's token spend: it became a request for the parent, or it was spent directly (or could not be). */
final readonly class TokenSpendOutcome
{
    private function __construct(public ?ApprovalRequest $request, public ?SpendResult $spend) {}

    public static function requested(ApprovalRequest $request): self
    {
        return new self($request, null);
    }

    public static function spent(SpendResult $result): self
    {
        return new self(null, $result);
    }

    public function needsApproval(): bool
    {
        return $this->request !== null;
    }

    public function wasSpent(): bool
    {
        return $this->spend === SpendResult::Spent;
    }
}
