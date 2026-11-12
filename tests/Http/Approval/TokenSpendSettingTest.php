<?php

declare(strict_types=1);

namespace Tests\Http\Approval;

use PHPUnit\Framework\Attributes\Group;

final class TokenSpendSettingTest extends ApprovalHttpTestCase
{
    private function setting(string $value, ?string $profile = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->post('/family/children/' . ($profile ?? $this->mia) . '/settings', ['token_spend_without_approval' => $value]);
    }

    private function stored(): bool
    {
        return (bool) $this->scalar('SELECT token_spend_without_approval FROM player_profiles WHERE id = :p', ['p' => $this->mia]);
    }

    #[Group('AC-42')]
    public function testTheSettingIsOffByDefault(): void
    {
        $newChild = $this->childOf($this->parent, 'Max', 'Smith', '2016-02-02');

        self::assertFalse($this->stored());
        self::assertFalse((bool) $this->scalar('SELECT token_spend_without_approval FROM player_profiles WHERE id = :p', ['p' => $newChild]));
        $this->signInAsParent();
        $page = $this->body($this->get('/family/children/' . $this->mia . '/settings'));
        self::assertStringContainsString('Allow token spending without approval', $page);
        self::assertStringNotContainsString(' checked', $page);
        self::assertStringContainsString('token spending needs your approval', $this->body($this->get('/family/children')));
    }

    #[Group('AC-42')]
    public function testTheParentTurnsItOnAndOffAtAnyTime(): void
    {
        $this->signInAsParent();

        $on = $this->setting('1');
        self::assertSame(302, $on->getStatusCode());
        self::assertSame('/family/children/' . $this->mia . '/settings?notice=saved', $this->location($on));
        self::assertTrue($this->stored());
        self::assertStringContainsString(' checked', $this->body($this->get('/family/children/' . $this->mia . '/settings')));
        self::assertStringContainsString('token spending without approval', $this->body($this->get('/family/children')));

        $this->setting('0');
        self::assertFalse($this->stored());
        $this->setting('1');
        self::assertTrue($this->stored());
    }

    #[Group('AC-42')]
    public function testEachChangeIsAuditedAndAnUnchangedValueIsNot(): void
    {
        $this->signInAsParent();

        $this->setting('1');
        $this->setting('1');
        $this->setting('0');

        $statement = $this->pdo->query("SELECT payload FROM audit_log WHERE action = 'child.token_setting_changed' ORDER BY id");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $rows = $statement->fetchAll();
        self::assertCount(2, $rows);
        self::assertEquals(["profile_id" => $this->mia, "from" => false, "to" => true], json_decode((string) $rows[0]['payload'], true));
        self::assertEquals(["profile_id" => $this->mia, "from" => true, "to" => false], json_decode((string) $rows[1]['payload'], true));
    }

    #[Group('AC-42'), Group('AC-9')]
    public function testOnlyTheParentCanChangeItAndTheChildGetsForbidden(): void
    {
        $this->signInAsKid();

        self::assertSame(403, $this->setting('1')->getStatusCode());
        self::assertSame(403, $this->get('/family/children/' . $this->mia . '/settings')->getStatusCode());
        self::assertFalse($this->stored());
        foreach (['trainer', 'coach'] as $role) {
            $this->signInAs($role);
            self::assertSame(403, $this->setting('1')->getStatusCode(), $role);
        }
        self::assertFalse($this->stored());
    }

    #[Group('AC-42'), Group('AC-9')]
    public function testAnotherParentCannotChangeTheSetting(): void
    {
        $this->createUser('player', 'other@example.com', name: 'Olga Other');
        $this->cookies = [];
        $this->login('other@example.com');

        self::assertSame(404, $this->setting('1')->getStatusCode());
        self::assertSame(404, $this->get('/family/children/' . $this->mia . '/settings')->getStatusCode());
        self::assertSame(404, $this->setting('1', '00000000-0000-7000-8000-000000000000')->getStatusCode());
        self::assertFalse($this->stored());
    }

    #[Group('AC-42')]
    public function testTheSelfProfileHasNoSuchSetting(): void
    {
        $self = $this->selfOf($this->parent);
        $this->signInAsParent();

        self::assertSame(404, $this->setting('1', $self)->getStatusCode());
        self::assertFalse((bool) $this->scalar('SELECT token_spend_without_approval FROM player_profiles WHERE id = :p', ['p' => $self]));
    }
}
