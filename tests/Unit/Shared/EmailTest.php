<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Email;
use App\Shared\Http\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[Group('AC-6')]
    public function testMixedCaseEmailIsTrimmedAndLowercased(): void
    {
        $email = Email::fromString("  Jane.Doe@Example.COM \n");
        self::assertSame('jane.doe@example.com', $email->value);
        self::assertTrue($email->equals(Email::fromString('JANE.DOE@example.com')));
    }

    #[Group('AC-70')]
    public function testInvalidEmailNamesTheField(): void
    {
        try {
            Email::fromString('not-an-email');
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('email', $e->errors);
        }
    }

    #[Group('AC-70')]
    public function testEmptyEmailIsRequiredAndUsesGivenField(): void
    {
        try {
            Email::fromString('  ', 'login_email');
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['login_email' => 'Email is required.'], $e->errors);
        }
    }
}
