<?php

declare(strict_types=1);

namespace Tests\Unit\Identity;

use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\PasswordPolicy;
use App\Shared\Http\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/PasswordHashSpy.php';

#[Group('AC-4')]
final class PasswordHasherTest extends TestCase
{
    private const CHEAP = ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1];

    public function testHashIsArgon2idAndNeverThePlaintext(): void
    {
        $hasher = new PasswordHasher(self::CHEAP);
        $hash = $hasher->hash('a long enough secret');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString('a long enough secret', $hash);
        self::assertTrue($hasher->verify('a long enough secret', $hash));
        self::assertFalse($hasher->verify('another secret!!', $hash));
        self::assertNotSame($hash, $hasher->hash('a long enough secret'), 'salted');
    }

    public function testWeakerHashesAreFlaggedForRehash(): void
    {
        $weak = (new PasswordHasher(self::CHEAP))->hash('a long enough secret');

        self::assertTrue((new PasswordHasher(['memory_cost' => 2048, 'time_cost' => 1, 'threads' => 1]))->needsRehash($weak));
        self::assertFalse((new PasswordHasher(self::CHEAP))->needsRehash($weak));
    }

    public function testPolicyEnforcesLengthAndCommonPasswords(): void
    {
        $policy = new PasswordPolicy();
        $policy->assertAcceptable('a perfectly fine passphrase');

        foreach (['short', str_repeat('x', 129), 'Password123', '1234567890'] as $bad) {
            try {
                $policy->assertAcceptable($bad);
                self::fail("accepted: {$bad}");
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey('password', $e->errors);
            }
        }
    }

    #[Group('AC-2')]
    public function testDummyVerifyNeverComputesAHash(): void
    {
        $GLOBALS['password_hash_calls'] = 0;

        (new PasswordHasher(self::CHEAP))->dummyVerify('whatever the visitor typed');

        self::assertSame(0, $GLOBALS['password_hash_calls'], 'the unknown-email path must not run password_hash');
    }

    #[Group('AC-2')]
    public function testDummyHashIsAnArgon2idHashWithTheProductionOptions(): void
    {
        $info = password_get_info(PasswordHasher::DUMMY_HASH);

        self::assertSame('argon2id', $info['algoName']);
        self::assertFalse((new PasswordHasher())->needsRehash(PasswordHasher::DUMMY_HASH), 'same options as production hashing');
        self::assertTrue(password_verify('dummy-password-for-timing-equalisation', PasswordHasher::DUMMY_HASH));
    }
}
