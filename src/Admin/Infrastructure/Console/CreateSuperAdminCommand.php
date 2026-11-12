<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Console;

use App\Identity\Domain\EmailAlreadyRegistered;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Console\Command;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Email;
use App\Shared\Http\Exception\ValidationFailed;
use App\Shared\Infrastructure\Audit\AuditLogger;
use App\Shared\Infrastructure\Pdo\TransactionManager;

/**
 * Creates the first Super Admin from the console only (there is no web route for it).
 *
 *   user:create-super-admin --email=ada@example.com --name="Ada Admin" --password-stdin < secret.txt
 *
 * Missing values are prompted. The password is never accepted as an argument (it would show in the
 * process list), never echoed and never logged.
 */
final class CreateSuperAdminCommand implements Command
{
    /** @var resource */
    private $stdin;
    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdin
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly TransactionManager $transaction,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        $stdin = null,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function name(): string
    {
        return 'user:create-super-admin';
    }

    public function description(): string
    {
        return 'Create a Super Admin (--email=, --name=, --password-stdin; prompts for anything missing)';
    }

    public function run(array $args): int
    {
        $options = [];
        $passwordFromStdin = false;
        foreach ($args as $arg) {
            if ($arg === '--password-stdin') {
                $passwordFromStdin = true;
            } elseif (preg_match('/^--(email|name)=(.*)$/s', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            } else {
                fwrite($this->stderr, "Unknown argument: {$arg}\n");

                return 2;
            }
        }

        $emailInput = $options['email'] ?? $this->prompt('Email: ');
        $name = trim($options['name'] ?? $this->prompt('Name: '));
        $password = $passwordFromStdin ? $this->readLine() : $this->promptSecret('Password: ');

        try {
            $email = Email::fromString($emailInput);
            if ($name === '') {
                throw ValidationFailed::forField('name', 'Name is required.');
            }
            $this->policy->assertAcceptable($password);
        } catch (ValidationFailed $e) {
            foreach ($e->errors as $field => $message) {
                fwrite($this->stderr, "{$field}: {$message}\n");
            }

            return 1;
        }

        try {
            $user = $this->transaction->run(function () use ($email, $name, $password) {
                $user = $this->users->create($email, $name, Role::SuperAdmin, $this->hasher->hash($password), $this->clock->now());
                $this->audit->log('superadmin.created', null, 'user', $user->id, null, ['role' => Role::SuperAdmin->value, 'via' => 'console']);

                return $user;
            });
        } catch (EmailAlreadyRegistered) {
            fwrite($this->stderr, "email: This email is already registered.\n");

            return 1;
        }

        fwrite($this->stdout, "Super Admin created (id {$user->id}). Sign in at /login.\n");

        return 0;
    }

    private function readLine(): string
    {
        $line = fgets($this->stdin);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private function prompt(string $label): string
    {
        fwrite($this->stdout, $label);

        return $this->readLine();
    }

    private function promptSecret(string $label): string
    {
        fwrite($this->stdout, $label);
        $interactive = stream_isatty($this->stdin);
        if ($interactive) {
            shell_exec('stty -echo');
        }
        $value = $this->readLine();
        if ($interactive) {
            shell_exec('stty echo');
            fwrite($this->stdout, "\n");
        }

        return $value;
    }
}
