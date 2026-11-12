<?php

declare(strict_types=1);

use App\Shared\Domain\Clock;
use App\Shared\Domain\ContextOptions;
use App\Shared\Domain\SystemClock;
use App\Shared\Infrastructure\Pdo\Database;
use App\Shared\Infrastructure\Pdo\TransactionManager;
use App\Shared\Infrastructure\View\TwigFactory;
use App\Shared\Http\CspNonce;
use App\Shared\Http\Kernel;
use App\Shared\Http\View;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Audit\PdoAuditLogger;
use App\Shared\Infrastructure\Logging\StreamLogger;
use App\Shared\Infrastructure\Mail\MailSender;
use App\Shared\Infrastructure\Mail\SymfonyMailSender;
use App\Shared\Infrastructure\Outbox\EmailOutboxHandler;
use App\Shared\Infrastructure\Outbox\JobOutboxHandler;
use App\Shared\Infrastructure\Outbox\Outbox;
use App\Shared\Infrastructure\Outbox\OutboxWorker;
use App\Shared\Infrastructure\Outbox\PdoOutbox;
use App\Shared\Infrastructure\Scheduler\Scheduler;
use DI\ContainerBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Twig\Environment;
use Twig\TwigFunction;

use function DI\autowire;
use function DI\factory;

/**
 * Builds the PHP-DI container. Later modules add their bindings via config/container.d/*.php.
 *
 * @param array<string, mixed>|null $overrides replaces the settings array in tests
 */
return static function (?array $overrides = null): ContainerInterface {
    /** @var array<string, mixed> $settings */
    $settings = $overrides ?? require __DIR__ . '/settings.php';
    /** @var array{db: array{host: string, port: string, name: string, user: string, password: string}, app: array{base_path: string}, mail: array{dsn: string, from: string}} $settings */

    $builder = new ContainerBuilder();
    $builder->addDefinitions([
        'settings' => $settings,
        Clock::class => autowire(SystemClock::class),
        Database::class => factory(static fn(): Database => new Database(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $settings['db']['host'], $settings['db']['port'], $settings['db']['name']),
            $settings['db']['user'],
            $settings['db']['password'],
        )),
        PDO::class => factory(static fn(Database $db): PDO => $db->pdo()),
        TransactionManager::class => autowire(),
        LoggerInterface::class => autowire(StreamLogger::class),
        AuditLogger::class => autowire(PdoAuditLogger::class),
        Outbox::class => autowire(PdoOutbox::class),
        MailerInterface::class => factory(static fn(): MailerInterface => new Mailer(Transport::fromDsn($settings['mail']['dsn']))),
        MailSender::class => factory(static fn(MailerInterface $mailer): MailSender => new SymfonyMailSender($mailer, $settings['mail']['from'])),
        // Modules append [topic, service id] pairs here (config/container.d/*.php, DI\add).
        'job.handlers' => [],
        JobOutboxHandler::class => factory(static function (ContainerInterface $c): JobOutboxHandler {
            $handler = new JobOutboxHandler();
            /** @var list<array{string, string}> $registrations */
            $registrations = (array) $c->get('job.handlers');
            foreach ($registrations as [$topic, $serviceId]) {
                $service = $c->get($serviceId);
                \assert(is_callable($service));
                $handler->register($topic, $service);
            }

            return $handler;
        }),
        OutboxWorker::class => factory(static fn(
            PDO $pdo,
            TransactionManager $tx,
            Clock $clock,
            EmailOutboxHandler $email,
            JobOutboxHandler $jobs,
            LoggerInterface $logger,
        ): OutboxWorker => new OutboxWorker($pdo, $tx, $clock, ['email' => $email, 'job' => $jobs], $logger)),
        // Modules append ContextSource services here (config/container.d/*.php, DI\add).
        'context.sources' => [],
        ContextOptions::class => factory(static fn(ContainerInterface $c): ContextOptions => new ContextOptions(array_map(static fn(string $id) => $c->get($id), (array) $c->get('context.sources')))),
        // Scheduled tasks are appended by later modules via config/container.d/*.php ('scheduler.tasks').
        'scheduler.tasks' => [],
        Scheduler::class => factory(static fn(PDO $pdo, Clock $clock, LoggerInterface $logger, ContainerInterface $c): Scheduler => new Scheduler(
            $pdo,
            $clock,
            array_map(static fn(string $id) => $c->get($id), (array) $c->get('scheduler.tasks')),
            $logger,
        )),
        ResponseFactoryInterface::class => autowire(Psr17Factory::class),
        CspNonce::class => autowire(),
        View::class => autowire(),
        Environment::class => factory(static function (CspNonce $nonce) use ($settings): Environment {
            $twig = TwigFactory::create($settings['app']['base_path'] . '/templates');
            $twig->addGlobal('app_name', 'PracticePerfect');
            $twig->addFunction(new TwigFunction('csp_nonce', static fn(): string => $nonce->value()));

            return $twig;
        }),
        Kernel::class => factory(static function (ContainerInterface $c): Kernel {
            /** @var list<class-string<Psr\Http\Server\MiddlewareInterface>> $middleware */
            $middleware = require __DIR__ . '/middleware.php';
            /** @var callable(League\Route\Router, ContainerInterface): void $routes */
            $routes = require __DIR__ . '/routes.php';

            return new Kernel($c, $middleware, [$routes]);
        }),
    ]);

    foreach (glob(__DIR__ . '/container.d/*.php') ?: [] as $file) {
        /** @var array<string, mixed> $definitions */
        $definitions = require $file;
        $builder->addDefinitions($definitions);
    }

    return $builder->build();
};
