<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use PHPUnit\Framework\Attributes\Group;

/** F-04 / AC-55: joining a Trainer is a POST, so an impersonating admin's join is audited and refused for an inactive target. */
final class ImpersonatedJoinTest extends ImpersonationTestCase
{
    private string $player;
    private string $me;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->player = $this->createUser('player', 'pat@example.com', name: 'Pat Player');
        $this->me = $this->selfOf($this->player);
        [, $this->code] = $this->shareLink($this->org);
    }

    #[Group('AC-55')]
    public function testAnImpersonatedJoinIsAuditedWithTheAdminIdentity(): void
    {
        $this->impersonate($this->player);
        $before = (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.action'");
        self::assertSame(200, $this->get('/join/' . $this->code)->getStatusCode());
        self::assertSame(0, $this->activeAssociationsOfMe(), 'the GET joins nobody');
        self::assertSame($before, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.action'"));

        $response = $this->post('/join/' . $this->code);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->activeAssociationsOfMe());
        $row = $this->row("SELECT impersonator_user_id, payload FROM audit_log WHERE action = 'impersonation.action' ORDER BY id DESC LIMIT 1");
        self::assertIsArray($row);
        self::assertSame($this->admin, $row['impersonator_user_id']);
        self::assertSame('POST', json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR)['method']);
    }

    #[Group('AC-55')]
    public function testAnImpersonatedJoinOfAnInactiveTargetIsRefusedAndLogged(): void
    {
        $this->deactivate($this->player);
        $this->impersonate($this->player);

        $refused = $this->post('/join/' . $this->code);

        self::assertSame(403, $refused->getStatusCode());
        self::assertSame(0, $this->activeAssociationsOfMe());
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM audit_log WHERE action = 'impersonation.action' AND payload->>'refused' = 'true'"));
    }

    private function activeAssociationsOfMe(): int
    {
        return $this->activeAssociations($this->me);
    }
}
