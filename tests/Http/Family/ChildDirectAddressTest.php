<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** AC-47: a child login that types a forbidden address gets 403 and nothing changes. */
final class ChildDirectAddressTest extends FamilyHttpTestCase
{
    private string $orgA;
    private string $orgB;
    private string $parent;
    private string $mia;
    private string $kid;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->orgA] = $this->trainerWithOrganization('Ace Tennis Academy');
        [, $this->orgB] = $this->trainerWithOrganization('Baseline Club');
        [, $this->code] = $this->shareLink($this->orgB);
        $this->parent = $this->signInAs('player');
        $this->selfOf($this->parent);
        $this->mia = $this->childOf($this->parent);
        $this->associate($this->mia, $this->orgA);
        $this->kid = $this->signInAsChildLogin($this->parent, $this->mia);
    }

    /** @return array<string, array{string, string, string}> method, path template, why */
    public static function forbiddenRoutes(): array
    {
        return [
            'add a child' => ['POST', '/family/children/new', 'add'],
            'children list (parent data)' => ['GET', '/family/children', 'parent data'],
            'add Trainer by code' => ['POST', '/family/children/{mia}/trainers/code', 'add Trainers'],
            'add Trainer from list' => ['POST', '/family/children/{mia}/trainers/add', 'add Trainers'],
            'Trainer prompt' => ['GET', '/family/children/{mia}/trainers', 'add Trainers'],
            'remove association (page)' => ['GET', '/family/children/{mia}/trainers/{orgA}/remove', 'change associations'],
            'remove association (confirm)' => ['POST', '/family/children/{mia}/trainers/{orgA}/remove', 'change associations'],
            'own login setup' => ['GET', '/family/children/{mia}/login', 'parent data'],
            'parent Me profile' => ['POST', '/family/me', 'parent data'],
            'review a registration' => ['POST', '/family/join/{code}', 'change associations'],
            'payment methods (view)' => ['GET', '/family/payment-methods', 'payment methods'],
            'payment methods (change)' => ['POST', '/family/payment-methods', 'payment methods'],
            'buy tokens' => ['POST', '/family/tokens/buy', 'purchase tokens'],
            'complete a purchase' => ['POST', '/family/purchases/complete', 'complete a purchase'],
            'delete the account' => ['POST', '/family/account/delete', 'delete the account'],
            'answer a join checklist' => ['POST', '/join/{code}/who', 'add Trainers'],
        ];
    }

    #[Group('AC-47'), DataProvider('forbiddenRoutes')]
    public function testEveryForbiddenAddressIsRefusedAndChangesNothing(string $method, string $path, string $why): void
    {
        $path = strtr($path, ['{mia}' => $this->mia, '{orgA}' => $this->orgA, '{code}' => $this->code]);
        $before = $this->writeFootprint();
        $ended = (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE ended_at IS NOT NULL');

        $response = $method === 'GET'
            ? $this->get($path)
            : $this->post($path, ['first_name' => 'X', 'last_name' => 'Y', 'birth_date' => '2020-01-01', 'gender' => 'male', 'code' => $this->code, 'organization_id' => $this->orgB, 'child' => $this->mia, 'profile_ids' => [$this->mia]]);

        self::assertSame(403, $response->getStatusCode(), "{$method} {$path} ({$why})");
        self::assertSame($before, $this->writeFootprint(), $why);
        self::assertSame($ended, (int) $this->scalar('SELECT count(*) FROM player_trainer_associations WHERE ended_at IS NOT NULL'));
        self::assertSame(1, $this->activeAssociations($this->mia));
        self::assertSame('active', $this->scalar('SELECT status FROM users WHERE id = :i', ['i' => $this->kid]));
    }

    #[Group('AC-47')]
    public function testTheSameAddressesAreNotRefusedForTheParent(): void
    {
        $this->cookies = [];
        $email = (string) $this->scalar('SELECT email FROM users WHERE id = :i', ['i' => $this->parent]);
        $this->login($email);

        self::assertSame(200, $this->get('/family/children')->getStatusCode());
        self::assertSame(501, $this->get('/family/payment-methods')->getStatusCode(), 'reserved for a later epic, but not a 403');
    }

    #[Group('AC-47')]
    public function testAChildCannotReachTheParentsDataThroughTheContextSelector(): void
    {
        $me = (string) $this->scalar("SELECT id FROM player_profiles WHERE kind = 'self'");
        $this->associate($me, $this->orgB);

        $response = $this->post('/family/context', ['context' => $this->orgB . ':' . $me]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Baseline Club', $this->body($this->get('/family/context')));
    }

    #[Group('AC-47')]
    public function testAnAnonymousVisitorStillGoesToLoginNotToForbidden(): void
    {
        $this->cookies = [];

        self::assertStringStartsWith('/login', $this->location($this->get('/family/payment-methods')));
    }
}
