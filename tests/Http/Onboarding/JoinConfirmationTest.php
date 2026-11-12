<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Integration\Application\RegistrationPrefillWriter;
use App\Shared\Domain\OrganizationId;
use PHPUnit\Framework\Attributes\Group;

/** F-04: opening a link or a pre-fill address (GET) changes nothing; the CSRF-protected POST does the work. */
final class JoinConfirmationTest extends OnboardingHttpTestCase
{
    private string $org;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->org] = $this->trainerWithOrganization();
        [, $this->code] = $this->shareLink($this->org);
    }

    #[Group('AC-21')]
    public function testAGetFromASignedInPlayerShowsTheConfirmationAndWritesNothing(): void
    {
        $player = $this->signInAs('player');
        $me = $this->selfOf($player);
        $before = $this->writeFootprint();

        $response = $this->get('/join/' . $this->code);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertStringContainsString('Join Ace Tennis Academy', $body);
        self::assertStringContainsString('action="/join/' . $this->code . '"', $body);
        self::assertStringContainsString('name="_csrf"', $body);
        self::assertSame($before, $this->writeFootprint());
        self::assertSame(0, $this->activeAssociations($me));
    }

    #[Group('AC-21')]
    public function testThePostAssociatesAndAWrongCsrfTokenIsRefused(): void
    {
        $player = $this->signInAs('player');
        $me = $this->selfOf($player);

        $forged = $this->post('/join/' . $this->code, ['_csrf' => 'forged']);
        self::assertSame(403, $forged->getStatusCode());
        self::assertSame(0, $this->activeAssociations($me));

        $ok = $this->post('/join/' . $this->code);
        self::assertSame('/family/events?notice=joined', $this->location($ok));
        self::assertSame(1, $this->activeAssociations($me));
    }

    #[Group('AC-22')]
    public function testAGetFromAParentWithChildrenOnlyShowsTheChecklist(): void
    {
        $parent = $this->signInAs('player');
        $this->selfOf($parent);
        $mia = $this->childOf($parent);
        $before = $this->writeFootprint();

        $response = $this->get('/join/' . $this->code);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Who will train', $this->body($response));
        self::assertSame($before, $this->writeFootprint());
        self::assertSame(0, $this->activeAssociations($mia));
    }

    #[Group('AC-49')]
    public function testAGetFromAChildWritesNothingAndOnlyThePostNotifiesTheParent(): void
    {
        $parent = $this->signInAs('player', 'parent@example.com', 'Pat Parent');
        $mia = $this->childOf($parent);
        $this->signInAsChildLogin($parent, $mia);
        $before = $this->writeFootprint();

        $get = $this->get('/join/' . $this->code);

        self::assertSame(200, $get->getStatusCode());
        self::assertStringContainsString('Ask my parent', $this->body($get));
        self::assertSame($before, $this->writeFootprint(), 'no link-use, notification, email or audit row from a GET');

        $post = $this->post('/join/' . $this->code);

        self::assertSame(200, $post->getStatusCode());
        self::assertStringContainsString('Ask your parent to register you with this trainer', $this->body($post));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'child-wants-to-join'"));
        self::assertSame(0, $this->activeAssociations($mia));
    }

    #[Group('AC-49')]
    public function testARepeatedChildRequestWithin24HoursSendsNoSecondNotificationOrEmail(): void
    {
        $parent = $this->signInAs('player', 'parent@example.com', 'Pat Parent');
        $mia = $this->childOf($parent);
        $this->signInAsChildLogin($parent, $mia);

        $this->post('/join/' . $this->code);
        $this->post('/join/' . $this->code);

        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'child-wants-to-join'"));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM notifications WHERE kind = 'child_wants_to_join'"));

        $this->pdo->exec("UPDATE notifications SET created_at = now() - interval '25 hours' WHERE kind = 'child_wants_to_join'");
        $this->post('/join/' . $this->code);

        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'child-wants-to-join'"), 'a new window allows a new notification');
    }

    #[Group('AC-49')]
    public function testTheWindowIsPerTrainer(): void
    {
        $parent = $this->signInAs('player', 'parent@example.com', 'Pat Parent');
        $mia = $this->childOf($parent);
        [, $other] = $this->trainerWithOrganization('Beta Academy');
        [, $otherCode] = $this->shareLink($other);
        $this->signInAsChildLogin($parent, $mia);

        $this->post('/join/' . $this->code);
        $this->post('/join/' . $otherCode);

        self::assertSame(2, (int) $this->scalar("SELECT count(*) FROM outbox WHERE topic = 'child-wants-to-join'"));
    }

    #[Group('AC-85')]
    public function testThePrefillGetFromASignedInAccountWritesNothingAndThePostAttaches(): void
    {
        $writer = $this->container->get(RegistrationPrefillWriter::class);
        self::assertInstanceOf(RegistrationPrefillWriter::class, $writer);
        $token = $writer->write(OrganizationId::fromString($this->org), ['name' => 'Pat Parent', 'email' => 'pat@example.com', 'phone' => '+14155552671', 'player_for' => 'me', 'birth_date' => '1990-04-02', 'gender' => 'other']);
        $user = $this->createUser('player', 'pat@example.com');
        $me = $this->selfOf($user);
        $this->login('pat@example.com');
        $before = $this->writeFootprint();

        $get = $this->get('/register/prefill/' . $token);

        self::assertSame(200, $get->getStatusCode());
        self::assertStringContainsString('/register/prefill/' . $token . '/attach', $this->body($get));
        self::assertSame($before, $this->writeFootprint());
        self::assertNull($this->scalar('SELECT consumed_at FROM registration_prefills'), 'the token is not consumed by a GET');
        self::assertSame(0, $this->activeAssociations($me));

        $forged = $this->post('/register/prefill/' . $token . '/attach', ['_csrf' => 'forged']);
        self::assertSame(403, $forged->getStatusCode());
        self::assertSame(0, $this->activeAssociations($me));

        $post = $this->post('/register/prefill/' . $token . '/attach');

        self::assertSame('/family/events?notice=joined', $this->location($post));
        self::assertSame(1, $this->activeAssociations($me));
        self::assertNotNull($this->scalar('SELECT consumed_at FROM registration_prefills'));
    }
}
