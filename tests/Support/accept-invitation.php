<?php

declare(strict_types=1);

/**
 * Child process of ConcurrentAcceptTest: accepts a Coach invitation in its own PHP process and
 * database connection, so two of them really compete for the same rows.
 *
 * usage: php accept-invitation.php <token> guest|user:<userId>
 * prints: accepted | refused:<reason> | error:<message>
 */

use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\UserRepository;
use App\Organization\Application\AcceptCoachInvitation;
use App\Organization\Application\CoachRegistration;
use App\Organization\Domain\InvitationRefused;
use Psr\Log\NullLogger;

require __DIR__ . '/../../vendor/autoload.php';

[, $token, $mode] = $argv + [null, '', 'guest'];
$container = (require __DIR__ . '/../../config/container.php')();
assert($container instanceof DI\Container);
$container->set(Psr\Log\LoggerInterface::class, new NullLogger());
$container->set(PasswordHasher::class, new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]));

try {
    $accept = $container->get(AcceptCoachInvitation::class);
    assert($accept instanceof AcceptCoachInvitation);
    $actor = null;
    $registration = null;
    if (str_starts_with((string) $mode, 'user:')) {
        $users = $container->get(UserRepository::class);
        assert($users instanceof UserRepository);
        $user = $users->findById(substr((string) $mode, 5)) ?? throw new RuntimeException('no such user');
        $actor = CurrentUser::fromUser($user);
    } else {
        $registration = new CoachRegistration('Racing Coach', 'a long coach password');
    }
    $accept->execute((string) $token, $actor, $registration);
    echo 'accepted';
} catch (InvitationRefused $e) {
    echo 'refused:' . $e->reason->value;
} catch (Throwable $e) {
    echo 'error:' . $e::class . ': ' . $e->getMessage();
}
