<?php

declare(strict_types=1);

/**
 * Child process of DecideRaceTest: the parent decides a request in its own PHP process and database
 * connection, so two of them really compete for the same row.
 *
 * usage: php decide-request.php <requestId> <parentUserId> approve|deny|info
 * prints: decided:<status> | refused:<reason class> | error:<message>
 */

use App\Approval\Application\DecideRequest;
use App\Approval\Application\Decision;
use App\Identity\Domain\CurrentUser;
use App\Identity\Domain\UserRepository;
use App\Shared\Http\Exception\Conflict;
use App\Shared\Http\Exception\NotFound;
use Psr\Log\NullLogger;

require __DIR__ . '/../../vendor/autoload.php';

[, $requestId, $parentId, $decision] = $argv + [null, '', '', 'approve'];
$container = (require __DIR__ . '/../../config/container.php')();
assert($container instanceof DI\Container);
$container->set(Psr\Log\LoggerInterface::class, new NullLogger());

try {
    $users = $container->get(UserRepository::class);
    $decide = $container->get(DecideRequest::class);
    assert($users instanceof UserRepository && $decide instanceof DecideRequest);
    $parent = $users->findById((string) $parentId) ?? throw new RuntimeException('no such parent');
    $result = $decide->execute(CurrentUser::fromUser($parent), (string) $requestId, Decision::from((string) $decision));
    echo 'decided:' . $result->status->value;
} catch (Conflict) {
    echo 'refused:conflict';
} catch (NotFound) {
    echo 'refused:not_found';
} catch (Throwable $e) {
    echo 'error:' . $e::class . ': ' . $e->getMessage();
}
