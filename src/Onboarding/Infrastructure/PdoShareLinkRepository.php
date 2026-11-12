<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Onboarding\Domain\ActiveLinkExists;
use App\Onboarding\Domain\ResolvedShareLink;
use App\Onboarding\Domain\ShareLink;
use App\Onboarding\Domain\ShareLinkCode;
use App\Onboarding\Domain\ShareLinkRepository;
use App\Shared\Domain\OrganizationId;
use App\Shared\Infrastructure\Pdo\TenantRepository;
use DateTimeImmutable;
use PDOException;

final class PdoShareLinkRepository extends TenantRepository implements ShareLinkRepository
{
    public function findActivePlayerLink(OrganizationId $organization): ?ShareLink
    {
        $stmt = $this->pdo->prepare("SELECT * FROM share_links WHERE active AND kind = 'player' AND " . $this->scope());
        $stmt->execute($this->scopeParams($organization));
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function createPlayerLink(OrganizationId $organization, string $code, DateTimeImmutable $at): ShareLink
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO share_links (organization_id, kind, code, created_at) VALUES (:organization_id, 'player', :code, :at) RETURNING *",
            );
            $stmt->execute($this->scopeParams($organization, ['code' => $code, 'at' => $at->format('Y-m-d H:i:s.uP')]));
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'share_links_one_active_per_organization')) {
                throw new ActiveLinkExists('The organization already has an active player link.', 0, $e);
            }
            throw $e;
        }
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new \LogicException('Insert returned no row.');
        }

        return $this->hydrate($row);
    }

    public function deactivatePlayerLink(OrganizationId $organization, DateTimeImmutable $at): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE share_links SET active = false, deactivated_at = :at WHERE active AND kind = 'player' AND " . $this->scope(),
        );
        $stmt->execute($this->scopeParams($organization, ['at' => $at->format('Y-m-d H:i:s.uP')]));

        return $stmt->rowCount() > 0;
    }

    public function findUsableByCode(string $code): ?ResolvedShareLink
    {
        if (!ShareLinkCode::isWellFormed($code)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT l.*, o.business_name
             FROM share_links l
             JOIN organizations o ON o.id = l.organization_id
             JOIN users u ON u.id = o.owner_user_id
             WHERE l.code = :code AND l.active AND o.status = 'active' AND u.status = 'active'",
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        return is_array($row) ? new ResolvedShareLink($this->hydrate($row), (string) $row['business_name']) : null;
    }

    public function recordUse(OrganizationId $organization, string $shareLinkId, ?string $userId, ?string $playerProfileId, string $outcome, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.uP');
        $update = $this->pdo->prepare('UPDATE share_links SET use_count = use_count + 1, last_used_at = :at WHERE id = :id AND ' . $this->scope());
        $update->execute($this->scopeParams($organization, ['at' => $stamp, 'id' => $shareLinkId]));
        if ($update->rowCount() !== 1) {
            throw new \LogicException('Share link not found in the organization.');
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO share_link_uses (share_link_id, user_id, player_profile_id, outcome, used_at) VALUES (:link, :user, :profile, :outcome, :at)',
        );
        $insert->execute(['link' => $shareLinkId, 'user' => $userId, 'profile' => $playerProfileId, 'outcome' => $outcome, 'at' => $stamp]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ShareLink
    {
        return new ShareLink(
            (string) $row['id'],
            (string) $row['organization_id'],
            (string) $row['code'],
            (bool) $row['active'],
            new DateTimeImmutable((string) $row['created_at']),
            (int) $row['use_count'],
            is_string($row['last_used_at']) ? new DateTimeImmutable($row['last_used_at']) : null,
        );
    }
}
