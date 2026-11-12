<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** The frozen clock is 2030-03-01, so a child born 2012-03-01 turns 18 that day. */
final class AgeRangeTest extends FamilyHttpTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function births(): iterable
    {
        yield 'newborn (age 0)' => ['2030-02-01', true];
        yield 'age 1' => ['2029-02-01', true];
        yield 'age 17 (turns 18 tomorrow)' => ['2012-03-02', true];
        yield 'age 18 today' => ['2012-03-01', false];
        yield 'age 40' => ['1990-01-01', false];
        yield 'born in the future (age -1)' => ['2031-03-01', false];
    }

    #[Group('AC-28')]
    #[DataProvider('births')]
    public function testOnlyAgesZeroToSeventeenAreAccepted(string $birth, bool $accepted): void
    {
        $parent = $this->signInAs('player');

        $response = $this->addChild(['birth_date' => $birth]);

        if ($accepted) {
            self::assertSame(302, $response->getStatusCode());
            self::assertSame(1, $this->childCount($parent));

            return;
        }
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->childCount($parent));
    }

    #[Group('AC-28')]
    public function testTheRefusalNamesTheRangeAndKeepsTheFormValues(): void
    {
        $this->signInAs('player');

        $response = $this->addChild(['birth_date' => '2012-03-01', 'first_name' => 'Kept']);

        self::assertStringContainsString('between 0 and 17 years old', $this->body($response));
        self::assertStringContainsString('href="#field-birth_date"', $this->body($response));
        self::assertStringContainsString('value="Kept"', $this->body($response));
    }

    #[Group('AC-28')]
    public function testAMalformedOrImpossibleDateIsRefused(): void
    {
        $this->signInAs('player');

        foreach (['not-a-date', '2020-02-31', '01/02/2020'] as $bad) {
            $response = $this->addChild(['birth_date' => $bad]);
            self::assertSame(422, $response->getStatusCode(), $bad);
            self::assertStringContainsString('YYYY-MM-DD', $this->body($response));
        }
    }
}
