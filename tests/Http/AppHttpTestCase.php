<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\Session;
use App\Shared\Domain\Clock;
use App\Shared\Domain\FrozenClock;
use App\Shared\Http\Kernel;
use App\Shared\Infrastructure\Storage\FileStorage;
use App\Shared\Infrastructure\Storage\LocalFileStorage;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PDO;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests against the real database: every test runs inside a rolled-back transaction, with a
 * FrozenClock and a cheap Argon2id configuration, and a browser-like cookie jar that follows
 * Set-Cookie headers. POST helpers add the CSRF token the way a browser form would.
 */
abstract class AppHttpTestCase extends HttpTestCase
{
    protected PDO $pdo;
    protected FrozenClock $clock;
    protected PasswordHasher $hasher;
    protected Kernel $kernel;
    /** Private upload storage of this test (a temporary directory, removed afterwards). */
    protected string $storageRoot;
    protected FileStorage $storage;

    /** @var array<string, string> */
    protected array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2030-03-01 10:00:00+00'));
        $this->hasher = new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        assert($this->container instanceof \DI\Container);
        $this->container->set(Clock::class, $this->clock);
        $this->container->set(PasswordHasher::class, $this->hasher);
        $this->storageRoot = sys_get_temp_dir() . '/pp-storage-' . bin2hex(random_bytes(6));
        $this->storage = new LocalFileStorage($this->storageRoot);
        $this->container->set(FileStorage::class, $this->storage);

        $pdo = $this->container->get(PDO::class);
        assert($pdo instanceof PDO);
        $this->pdo = $pdo;
        $this->pdo->beginTransaction();
        $this->kernel = $this->kernel(...$this->extraRoutes());
    }

    /** @return list<callable(\League\Route\Router, \Psr\Container\ContainerInterface): void> routes added for one test class */
    protected function extraRoutes(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    /** @return int number of files stored of the kind (logos, photos or tmp), thumbnails included */
    protected function storedFiles(string $kind): int
    {
        return count(glob($this->storageRoot . '/' . $kind . '/*.*') ?: []);
    }

    /** An uploaded file the way the request parser hands it over; a non-OK error leaves the file unusable. */
    protected function upload(string $bytes, string $clientName = 'upload.png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile(\Nyholm\Psr7\Stream::create($error === UPLOAD_ERR_OK ? $bytes : ''), $error === UPLOAD_ERR_OK ? strlen($bytes) : 0, $error, $clientName, 'application/octet-stream');
    }

    protected function createUser(
        string $role = 'player',
        ?string $email = null,
        string $password = 'correct horse battery',
        string $status = 'active',
        bool $verified = true,
        bool $mustSetPassword = false,
        string $name = 'Test User',
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, name, role, status, password_hash, email_verified_at, must_set_password)
             VALUES (:email, :name, :role, :status, :hash, :verified, :must) RETURNING id',
        );
        $stmt->bindValue('email', $email ?? 'user' . bin2hex(random_bytes(4)) . '@example.com');
        $stmt->bindValue('name', $name);
        $stmt->bindValue('role', $role);
        $stmt->bindValue('status', $status);
        $stmt->bindValue('hash', $mustSetPassword ? null : $this->hasher->hash($password));
        $stmt->bindValue('verified', $verified ? '2030-01-01 00:00:00+00' : null);
        $stmt->bindValue('must', $mustSetPassword, PDO::PARAM_BOOL);
        $stmt->execute();

        return (string) $stmt->fetchColumn();
    }

    /**
     * Opens a server-side session for the user directly (for accounts that cannot sign in with a
     * password yet, such as one flagged must_set_password) and puts its cookie in the jar.
     */
    protected function startSession(string $userId): void
    {
        $token = Session::newToken();
        $stmt = $this->pdo->prepare('INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, last_seen_at) VALUES (:h, :u, :c, :t, :t)');
        $stmt->execute(['h' => Session::hashToken($token), 'u' => $userId, 'c' => Session::newToken(), 't' => $this->clock->now()->format('Y-m-d H:i:s.uP')]);
        $this->cookies['__Host-sid'] = $token;
    }

    /** Signs in as a freshly created user of the role and returns the user id. */
    protected function signInAs(string $role, ?string $email = null, string $name = 'Test User'): string
    {
        $email ??= $role . bin2hex(random_bytes(3)) . '@example.com';
        $id = $this->createUser($role, $email, name: $name);
        $this->cookies = [];
        $this->login($email);

        return $id;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function row(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $params */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string>|null $cookies overrides the jar when given
     */
    protected function get(string $uri, array $headers = [], ?array $cookies = null): ResponseInterface
    {
        return $this->send('GET', $uri, null, $headers, $cookies);
    }

    /**
     * POST with the CSRF token a browser form would carry (unless `_csrf` is given in $body).
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function post(string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        if (!array_key_exists('_csrf', $body)) {
            $body['_csrf'] = $this->csrfToken();
        }

        return $this->send('POST', $uri, $body, $headers);
    }

    /**
     * A multipart POST: form fields plus uploaded files, with the CSRF token a browser form would carry.
     *
     * @param array<string, mixed> $body
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     */
    protected function postFiles(string $uri, array $body, array $files): ResponseInterface
    {
        if (!array_key_exists('_csrf', $body)) {
            $body['_csrf'] = $this->csrfToken();
        }

        return $this->send('POST', $uri, $body, [], null, '203.0.113.9', $files);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, string>|null $cookies
     * @param array<string, \Psr\Http\Message\UploadedFileInterface> $files
     */
    protected function send(string $method, string $uri, ?array $body = null, array $headers = [], ?array $cookies = null, string $ip = '203.0.113.9', array $files = []): ResponseInterface
    {
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = (new ServerRequest($method, 'https://app.test' . $uri, $headers, null, '1.1', ['REMOTE_ADDR' => $ip]))
            ->withCookieParams($cookies ?? $this->cookies)
            ->withQueryParams($query);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        $response = $this->kernel->handle($request);
        if ($cookies === null) {
            $this->absorbCookies($response);
        }

        return $response;
    }

    protected function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    protected function absorbCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (stripos($line, 'Max-Age=0') !== false || $value === '') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }

    /** Token a form rendered for the current visitor (session token when signed in, else the guest cookie). */
    protected function csrfToken(): string
    {
        if (isset($this->cookies['__Host-sid'])) {
            $token = $this->scalar('SELECT csrf_token FROM sessions WHERE token_hash = :h', ['h' => Session::hashToken($this->cookies['__Host-sid'])]);
            if (is_string($token)) {
                return $token;
            }
        }
        if (!isset($this->cookies['__Host-csrf'])) {
            $this->absorbCookies($this->kernel->handle(new ServerRequest('GET', 'https://app.test/health')));
        }

        return $this->cookies['__Host-csrf'] ?? self::fail('No CSRF token available');
    }

    protected function login(string $email, string $password = 'correct horse battery'): ResponseInterface
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    protected function location(ResponseInterface $response): string
    {
        return $response->getHeaderLine('Location');
    }

    protected function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
