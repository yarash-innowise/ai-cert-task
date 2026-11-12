<?php

declare(strict_types=1);

namespace Tests\Http\Family;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class SimilarChildTest extends FamilyHttpTestCase
{
    #[Group('AC-30')]
    public function testASimilarChildWarnsFirstAndCreatesNothing(): void
    {
        $parent = $this->signInAs('player');
        $this->childOf($parent, 'Mia', 'Smith', '2022-05-01');

        $response = $this->addChild(['first_name' => 'mia', 'last_name' => 'SMITH', 'birth_date' => '2022-11-30']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Mia Smith', $this->body($response));
        self::assertStringContainsString('looks similar', $this->body($response));
        self::assertStringContainsString('name="confirm_similar" value="1"', $this->body($response));
        self::assertSame(1, $this->childCount($parent));
    }

    #[Group('AC-30')]
    public function testTheParentCanDismissTheWarningAndProceed(): void
    {
        $parent = $this->signInAs('player');
        $this->childOf($parent, 'Mia', 'Smith', '2022-05-01');

        $response = $this->addChild(['first_name' => 'Mia', 'last_name' => 'Smith', 'confirm_similar' => '1']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(2, $this->childCount($parent));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function candidates(): iterable
    {
        yield 'one typo, same year' => ['Mya', 'Smith', '2022-01-01', true];
        yield 'two edits, same year' => ['Miaaa', 'Smith', '2022-01-01', true];
        yield 'different name' => ['Oliver', 'Jones', '2022-05-01', false];
        yield 'same name, different birth year' => ['Mia', 'Smith', '2021-05-01', false];
    }

    #[Group('AC-30')]
    #[DataProvider('candidates')]
    public function testResemblanceIsCaseFoldedLevenshteinTwoWithTheSameBirthYear(string $first, string $last, string $birth, bool $warns): void
    {
        $parent = $this->signInAs('player');
        $this->childOf($parent, 'Mia', 'Smith', '2022-05-01');

        $response = $this->addChild(['first_name' => $first, 'last_name' => $last, 'birth_date' => $birth]);

        self::assertSame($warns ? 200 : 302, $response->getStatusCode());
        self::assertSame($warns ? 1 : 2, $this->childCount($parent));
    }

    #[Group('AC-30')]
    public function testAnotherParentsChildDoesNotTriggerTheWarning(): void
    {
        $other = $this->createUser('player', 'other@example.com');
        $this->childOf($other, 'Mia', 'Smith', '2022-05-01');
        $parent = $this->signInAs('player');

        self::assertSame(302, $this->addChild()->getStatusCode());
        self::assertSame(1, $this->childCount($parent));
    }
}
