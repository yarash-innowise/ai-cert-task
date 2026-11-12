<?php

declare(strict_types=1);

namespace Tests\Http\Branding;

use App\Identity\Domain\PasswordHasher;
use App\Shared\Domain\Clock;
use App\Shared\Infrastructure\Storage\FileStorage;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Tests\Http\Family\FamilyHttpTestCase;
use Tests\Support\ImageFixtures;

/** Fixtures for the Trainer branding tests. */
abstract class BrandingHttpTestCase extends FamilyHttpTestCase
{
    protected string $trainerId;
    protected string $orgId;
    protected string $trainerEmail;

    /** Creates a Trainer with an organization and signs in as them. */
    protected function signInAsTrainer(string $business = 'Ace Tennis Academy'): void
    {
        [$this->trainerId, $this->orgId] = $this->trainerWithOrganization($business);
        $this->trainerEmail = (string) $this->scalar('SELECT email FROM users WHERE id = :u', ['u' => $this->trainerId]);
        $this->cookies = [];
        $this->login($this->trainerEmail);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     */
    protected function saveBranding(array $fields = [], array $files = []): ResponseInterface
    {
        return $this->postFiles('/trainer/branding', ['action' => 'save', 'brand_color' => '#1A7F37', ...$fields], $files);
    }

    protected function logoUpload(string $bytes = '', string $name = 'logo.png'): \Nyholm\Psr7\UploadedFile
    {
        return ImageFixtures::file($bytes === '' ? ImageFixtures::png(60, 40) : $bytes, $name);
    }

    /** @return array{logo_path: string|null, brand_color: string|null} */
    protected function brandingRow(?string $organizationId = null): array
    {
        $row = $this->row('SELECT logo_path, brand_color FROM organizations WHERE id = :o', ['o' => $organizationId ?? $this->orgId]);

        return ['logo_path' => $row['logo_path'] ?? null, 'brand_color' => $row['brand_color'] ?? null];
    }

    /** Signs in as a parent whose child is associated with the organization. */
    protected function signInAsParentOf(string $organizationId): string
    {
        $parent = $this->signInAs('player');
        $this->associate($this->childOf($parent), $organizationId);

        return $parent;
    }

    protected function signInAsCoachOf(string $organizationId): string
    {
        $email = 'coach' . bin2hex(random_bytes(3)) . '@example.com';
        $coach = $this->createUser('coach', $email);
        $this->pdo->prepare("INSERT INTO coach_memberships (organization_id, coach_user_id, status) VALUES (:o, :c, 'active')")->execute(['o' => $organizationId, 'c' => $coach]);
        $this->cookies = [];
        $this->login($email);

        return $coach;
    }

    /**
     * Replaces services that were already used (and cached by the container) by starting a fresh container on the
     * same database connection, clock and storage, with the given overrides.
     *
     * @param array<string, object> $overrides service id => replacement
     */
    protected function withServices(array $overrides): void
    {
        $container = (require __DIR__ . '/../../../config/container.php')();
        assert($container instanceof \DI\Container);
        $container->set(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());
        $container->set(Clock::class, $this->clock);
        $container->set(PasswordHasher::class, $this->hasher);
        $container->set(FileStorage::class, $this->storage);
        $container->set(PDO::class, $this->pdo);
        foreach ($overrides as $id => $service) {
            $container->set($id, $service);
        }
        $this->container = $container;
        $this->kernel = $this->kernel();
    }
}
