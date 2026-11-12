<?php

declare(strict_types=1);

/**
 * Child process of ConcurrentAcceptTest: a Super Admin deletes a Trainer in its own PHP process and
 * database connection, so it really competes with an invitation acceptance for the organization row.
 *
 * usage: php delete-trainer.php <trainerUserId> <actorUserId>
 * prints: deleted | refused:<message> | error:<message>
 */

use App\Admin\Application\DeleteUser;
use App\Shared\Http\Exception\ValidationFailed;
use Psr\Log\NullLogger;

require __DIR__ . '/../../vendor/autoload.php';

[, $trainerId, $actorId] = $argv + [null, '', ''];
$container = (require __DIR__ . '/../../config/container.php')();
assert($container instanceof DI\Container);
$container->set(Psr\Log\LoggerInterface::class, new NullLogger());

try {
    $delete = $container->get(DeleteUser::class);
    assert($delete instanceof DeleteUser);
    $delete->execute((string) $trainerId, 'Race test deletion.', (string) $actorId);
    echo 'deleted';
} catch (ValidationFailed $e) {
    echo 'refused:' . implode(' ', $e->errors);
} catch (Throwable $e) {
    echo 'error:' . $e::class . ': ' . $e->getMessage();
}
