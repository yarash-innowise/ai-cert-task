<?php

declare(strict_types=1);

namespace App\Approval\Application;

use App\Approval\Domain\ApprovalRepository;
use App\Approval\Domain\ApprovalRequest;
use App\Approval\Domain\Policy\ApprovalRequestPolicy;
use App\Family\Domain\PlayerProfileRepository;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\Role;
use App\Shared\Domain\Clock;
use App\Shared\Http\Exception\Forbidden;
use App\Shared\Http\Exception\NotFound;

/** The parent's inbox and one request's page: only requests of the parent's own children (AC-45). */
final class ListParentApprovals
{
    public function __construct(
        private readonly ApprovalRepository $requests,
        private readonly ApprovalRequestPolicy $policy,
        private readonly PlayerProfileRepository $profiles,
        private readonly Clock $clock,
    ) {}

    /** @return list<ApprovalListItem> */
    public function execute(CurrentUser $actor): array
    {
        $this->assertParent($actor);

        return array_map($this->item(...), $this->requests->forParent($actor->id));
    }

    /** @throws NotFound not a request of the actor's children (another parent's request looks the same) */
    public function find(CurrentUser $actor, string $requestId): ApprovalListItem
    {
        $this->assertParent($actor);
        $request = $this->requests->findById($requestId);
        if ($request === null || !$this->policy->canViewAsParent($actor, $request)) {
            throw new NotFound();
        }

        return $this->item($request);
    }

    private function assertParent(CurrentUser $actor): void
    {
        if ($actor->role !== Role::Player || $actor->isChildLogin()) {
            throw new Forbidden();
        }
    }

    private function item(ApprovalRequest $request): ApprovalListItem
    {
        $child = $this->profiles->findById($request->childProfileId);

        return new ApprovalListItem($request, $child?->fullName() ?? 'Your child', $request->status->isDecidable() && !$request->expiredAt($this->clock->now()));
    }
}
