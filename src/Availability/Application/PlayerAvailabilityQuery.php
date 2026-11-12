<?php

declare(strict_types=1);

namespace App\Availability\Application;

use App\Availability\Domain\AvailabilityRepository;
use App\Availability\Domain\AvailabilitySubject;
use App\Availability\Domain\TimeRange;
use App\Availability\Domain\Weekday;
use App\Availability\Domain\WeeklyAvailability;
use App\Identity\Domain\CurrentUser;
use App\Organization\Application\ListOrganizationPlayers;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\RequestContext;

/**
 * What a Trainer sees of their players' availability (AC-63): active associations of the Trainer's own
 * organization only (the roster decides), each with a "Best Times" summary, optionally filtered by a day
 * and a time. Availability is advisory: a player with no entries is never excluded, and nothing here (or
 * anywhere in scheduling) treats a preference as a blocker. The whole page costs the same number of
 * statements however many players there are (roster + one batch for slots + one for "Not Available" days).
 */
final class PlayerAvailabilityQuery
{
    public const AVAILABLE = 'available';
    public const NO_PREFERENCE = 'no_preference';

    public function __construct(
        private readonly ListOrganizationPlayers $players,
        private readonly AvailabilityRepository $availability,
        private readonly BestTimes $bestTimes,
    ) {}

    /**
     * @param string|null $time "HH:MM"; only used together with a weekday
     * @return list<PlayerAvailabilityCard> players that fit first, then those with no preference
     * @throws \InvalidArgumentException the time is not an "HH:MM" time of day
     */
    public function execute(CurrentUser $actor, RequestContext $context, ?Weekday $day = null, ?string $time = null): array
    {
        $minute = $day !== null && $time !== null && trim($time) !== '' ? TimeRange::minuteOf($time) : null;
        $roster = $this->players->execute($actor, $context);
        $weeks = $this->availability->weeks(AvailabilitySubject::PlayerProfile, array_map(static fn($p): string => $p->profileId, $roster));

        $cards = [];
        foreach ($roster as $player) {
            $week = $weeks[$player->profileId] ?? WeeklyAvailability::empty();
            $match = $day === null ? null : $this->match($week, $day, $minute);
            if ($day !== null && $match === null) {
                continue;
            }
            $cards[] = new PlayerAvailabilityCard($player, $week, $this->bestTimes->of($week), $match);
        }
        if ($day !== null) {
            usort($cards, static fn(PlayerAvailabilityCard $a, PlayerAvailabilityCard $b): int => ($a->match === self::AVAILABLE ? 0 : 1) <=> ($b->match === self::AVAILABLE ? 0 : 1));
        }

        return $cards;
    }

    /** @throws NotFound the player is not on this Trainer's roster (another Trainer's player looks the same) */
    public function find(CurrentUser $actor, RequestContext $context, string $profileId): PlayerAvailabilityCard
    {
        $player = $this->players->find($actor, $context, $profileId);
        $week = $this->availability->week(AvailabilitySubject::PlayerProfile, $player->profileId);

        return new PlayerAvailabilityCard($player, $week, $this->bestTimes->of($week), null);
    }

    /** "available", "no_preference", or null when the player's own entries rule the day or time out. */
    private function match(WeeklyAvailability $week, Weekday $day, ?int $minute): ?string
    {
        if (!$week->hasEntries()) {
            return self::NO_PREFERENCE;
        }
        if ($week->isUnavailableOn($day)) {
            return null;
        }
        $ranges = $week->merged($day);
        if ($ranges === []) {
            return self::NO_PREFERENCE;
        }
        if ($minute === null) {
            return self::AVAILABLE;
        }
        foreach ($ranges as $range) {
            if ($range->includes($minute)) {
                return self::AVAILABLE;
            }
        }

        return null;
    }
}
