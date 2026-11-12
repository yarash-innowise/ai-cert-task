<?php

declare(strict_types=1);

namespace Tests\Http\Onboarding;

use App\Onboarding\Application\ResolveShareLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class ResolveShareLinkTest extends OnboardingHttpTestCase
{
    private function normalized(\Psr\Http\Message\ResponseInterface $response): string
    {
        return (string) preg_replace('/nonce="[^"]+"/', 'nonce=""', $this->body($response));
    }

    #[Group('AC-26')]
    public function testAnActiveLinkShowsTheTrainersName(): void
    {
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $code] = $this->shareLink($org);

        $response = $this->get('/join/' . $code);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Join Ace Tennis Academy', $this->body($response));
    }

    /** @return iterable<string, array{string}> */
    public static function unusable(): iterable
    {
        yield 'unknown code' => ['unknown'];
        yield 'deactivated link' => ['deactivated'];
        yield 'replaced link' => ['replaced'];
        yield 'inactive organization' => ['inactive_org'];
        yield 'deactivated Trainer' => ['inactive_trainer'];
        yield 'deleted Trainer' => ['deleted_trainer'];
        yield 'malformed code' => ['malformed'];
    }

    private function codeFor(string $case): string
    {
        switch ($case) {
            case 'unknown':
                return str_repeat('Z', 22);
            case 'malformed':
                return "bad'code";
            case 'deactivated':
                [, $org] = $this->trainerWithOrganization('Org 1');

                return $this->shareLink($org, null, false)[1];
            case 'replaced':
                [, $org] = $this->trainerWithOrganization('Org 2');
                $old = $this->shareLink($org, null, false)[1];
                $this->shareLink($org);

                return $old;
            case 'inactive_org':
                [, $org] = $this->trainerWithOrganization('Org 3', 'inactive');

                return $this->shareLink($org)[1];
            case 'inactive_trainer':
            case 'deleted_trainer':
                [$trainer, $org] = $this->trainerWithOrganization('Org 4');
                $this->pdo->prepare('UPDATE users SET status = :s WHERE id = :id')->execute(['s' => $case === 'deleted_trainer' ? 'deleted' : 'inactive', 'id' => $trainer]);

                return $this->shareLink($org)[1];
        }
        self::fail('unknown case');
    }

    #[Group('AC-26')]
    #[DataProvider('unusable')]
    public function testEveryUnusableCodeShowsTheSameClearMessageAndWritesNothing(string $case): void
    {
        $code = $this->codeFor($case);
        [, $validOrg] = $this->trainerWithOrganization('Reference');
        $reference = $this->get('/join/' . str_repeat('Q', 22));
        $before = $this->writeFootprint();

        $response = $this->get('/join/' . rawurlencode($code));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('This link is not valid', $this->body($response));
        self::assertSame($this->normalized($reference), $this->normalized($response), 'same page for every reason');
        self::assertSame($before, $this->writeFootprint());
        self::assertNotSame('', $validOrg);
    }

    #[Group('AC-26')]
    public function testTheUnusablePageNeverNamesTheTrainerOrTheReason(): void
    {
        [, $org] = $this->trainerWithOrganization('Secret Club Name', 'inactive');
        [, $code] = $this->shareLink($org);

        $body = $this->body($this->get('/join/' . $code));

        self::assertStringNotContainsString('Secret Club Name', $body);
        self::assertStringNotContainsString('inactive', $body);
        self::assertStringNotContainsString('deactivated', $body);
    }

    #[Group('AC-26')]
    public function testRevisitingAfterAParentRemovedTheChildAssociatesNobody(): void
    {
        $parent = $this->signInAs('player');
        $child = $this->childOf($parent);
        [, $org] = $this->trainerWithOrganization();
        [, $code] = $this->shareLink($org);
        $this->associate($child, $org);
        $this->post('/family/children/' . $child . '/trainers/' . $org . '/remove');
        $before = $this->writeFootprint();

        $response = $this->get('/join/' . $code);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($before, $this->writeFootprint());
        self::assertSame(0, $this->activeAssociations($child));
    }

    #[Group('AC-26')]
    public function testTheUseCaseReturnsNullForUnusableCodesAndTheLinkForAUsableOne(): void
    {
        [, $org] = $this->trainerWithOrganization('Ace Tennis Academy');
        [$id, $code] = $this->shareLink($org);
        $resolve = $this->container->get(ResolveShareLink::class);
        self::assertInstanceOf(ResolveShareLink::class, $resolve);

        $resolved = $resolve->execute($code);

        self::assertNotNull($resolved);
        self::assertSame($id, $resolved->link->id);
        self::assertSame('Ace Tennis Academy', $resolved->businessName);
        self::assertNull($resolve->execute(''));
        self::assertNull($resolve->execute(strtolower($code) === $code ? strtoupper($code) : strtolower($code)), 'codes are case-sensitive');
    }
}
