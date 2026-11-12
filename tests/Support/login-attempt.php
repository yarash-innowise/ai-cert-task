<?php

declare(strict_types=1);

/**
 * Child process of LoginThrottleRaceTest: one sign-in attempt in its own PHP process and database
 * connection, started at a shared moment so that several really compete.
 *
 * usage: php login-attempt.php <email> <password> <ip> <start unix time as float>
 * prints: invalid | locked | signed-in | error:<message>
 */

use App\Identity\Application\LoginUser;
use App\Identity\Domain\LoginRefused;
use Psr\Log\NullLogger;

require __DIR__ . '/../../vendor/autoload.php';

[, $email, $password, $ip, $startAt] = $argv + [null, '', '', '', '0'];
$container = (require __DIR__ . '/../../config/container.php')();
assert($container instanceof DI\Container);
$container->set(Psr\Log\LoggerInterface::class, new NullLogger());

try {
    $login = $container->get(LoginUser::class);
    assert($login instanceof LoginUser);
    $wait = (float) $startAt - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }
    $login->execute((string) $email, (string) $password, (string) $ip);
    echo 'signed-in';
} catch (LoginRefused $e) {
    echo $e->reason === App\Identity\Domain\LoginFailure::Locked ? 'locked' : 'invalid';
} catch (Throwable $e) {
    echo 'error:' . $e::class . ': ' . $e->getMessage();
}
