<?php

declare(strict_types=1);

namespace Tests\Http\Admin;

use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Email;
use PHPUnit\Framework\Attributes\Group;

/** Edge case: the original email is free again after the user was deleted. */
final class ReRegisterAfterDeleteTest extends LifecycleTestCase
{
    #[Group('AC-76')]
    public function testTheOriginalEmailCanRegisterAgainAsANewPerson(): void
    {
        $this->deleteUser($this->parent);
        $users = $this->container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $new = $users->create(Email::fromString(self::PARENT_EMAIL), 'Pat Parent Again', Role::Player, $this->hasher->hash('correct horse battery'), $this->clock->now());

        self::assertNotSame($this->parent, $new->id);
        self::assertSame('deleted', $this->statusOf($this->parent), 'the deleted record stays deleted');
        self::assertSame('Deleted User', $this->scalar('SELECT name FROM users WHERE id = :u', ['u' => $this->parent]));
        $this->cookies = [];
        self::assertSame(302, $this->login(self::PARENT_EMAIL)->getStatusCode(), 'the new account signs in');
        self::assertSame(1, (int) $this->scalar('SELECT count(*) FROM sessions WHERE user_id = :u', ['u' => $new->id]));
    }

    #[Group('AC-76')]
    public function testRegisteringThroughAShareLinkWithTheOriginalEmailWorksAfterTheDeletion(): void
    {
        [, $code] = $this->shareLink($this->org);
        $this->deleteUser($this->parent);
        $this->cookies = [];

        $page = $this->get('/join/' . $code . '/register');
        self::assertSame(200, $page->getStatusCode());
        $response = $this->post('/join/' . $code . '/register', [
            'player_for' => 'me', 'player_name' => '', 'birth_date' => '1985-06-01', 'gender' => 'other',
            'name' => 'Pat Parent', 'email' => self::PARENT_EMAIL, 'password' => 'correct horse battery', 'phone' => '+14155552671',
        ]);

        self::assertNotSame(422, $response->getStatusCode(), $this->body($response));
        self::assertSame(1, (int) $this->scalar("SELECT count(*) FROM users WHERE email = :e AND status <> 'deleted'", ['e' => self::PARENT_EMAIL]));
    }
}
