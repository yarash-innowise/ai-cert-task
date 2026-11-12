<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

final readonly class JoinResult
{
    private function __construct(public JoinStatus $status, public ?string $profileId = null) {}

    public static function joined(string $profileId): self
    {
        return new self(JoinStatus::Joined, $profileId);
    }

    public static function alreadyAssociated(string $profileId): self
    {
        return new self(JoinStatus::AlreadyAssociated, $profileId);
    }

    public static function chooseWho(): self
    {
        return new self(JoinStatus::ChooseWho);
    }

    public static function needsProfile(): self
    {
        return new self(JoinStatus::NeedsProfile);
    }
}
