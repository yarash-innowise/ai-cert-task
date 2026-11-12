<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Integration\Application\SendShareLinkByEmail;
use App\Shared\Domain\OrganizationId;
use App\Shared\Http\Exception\NotFound;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Mail\EmailRenderer;
use PHPUnit\Framework\Attributes\Group;

final class SendShareLinkByEmailTest extends OnboardingHttpTestCase
{
    private function sender(): SendShareLinkByEmail
    {
        $sender = $this->container->get(SendShareLinkByEmail::class);
        self::assertInstanceOf(SendShareLinkByEmail::class, $sender);

        return $sender;
    }

    #[Group('AC-85'), Group('AC-86')]
    public function testTheShareLinkEmailIsQueuedAndRendersTheTrainersLink(): void
    {
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $code] = $this->shareLink($org);

        $this->sender()->send(OrganizationId::fromString($org), ' Pat@Example.com ', 'Pat Parent');

        $row = $this->row("SELECT payload FROM outbox WHERE kind = 'email' AND topic = 'share-link'");
        self::assertIsArray($row);
        /** @var array{to: string, context: array<string, mixed>} $payload */
        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pat@example.com', $payload['to']);
        $renderer = $this->container->get(EmailRenderer::class);
        self::assertInstanceOf(EmailRenderer::class, $renderer);
        $mail = $renderer->render('share-link', $payload['to'], $payload['context'], 'm1');
        self::assertSame('Join Ace Tennis Academy', $mail->subject);
        self::assertStringContainsString('/join/' . $code, $mail->text);
        self::assertStringContainsString('/join/' . $code, $mail->html);
        self::assertStringContainsString('Pat Parent', $mail->text);
    }

    #[Group('AC-85')]
    public function testAnOrganizationWithoutALinkGetsOneCreated(): void
    {
        [, $org] = $this->trainerWithOrganization();

        $this->sender()->send(OrganizationId::fromString($org), 'pat@example.com');
        $this->sender()->send(OrganizationId::fromString($org), 'sam@example.com');

        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM share_links WHERE organization_id = :o AND active', ['o' => $org]));
        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'share-link'"));
    }

    #[Group('AC-85')]
    public function testAnInvalidAddressOrAnInactiveOrganizationSendsNothing(): void
    {
        [, $org] = $this->trainerWithOrganization();
        [, $inactive] = $this->trainerWithOrganization('Closed', 'inactive');

        try {
            $this->sender()->send(OrganizationId::fromString($org), 'not-an-email');
            self::fail('Expected a validation failure.');
        } catch (ValidationFailed) {
        }
        try {
            $this->sender()->send(OrganizationId::fromString($inactive), 'pat@example.com');
            self::fail('Expected a not-found failure.');
        } catch (NotFound) {
        }

        self::assertSame(0, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'share-link'"));
        self::assertSame(0, (int) $this->scalar('SELECT count(*) FROM share_links WHERE organization_id = :o', ['o' => $inactive]));
    }
}
