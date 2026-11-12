<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Clock;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ContainerTest extends TestCase
{
    public function testContainerResolvesCoreServices(): void
    {
        $factory = require __DIR__ . '/../../../config/container.php';
        $container = $factory();
        self::assertInstanceOf(Clock::class, $container->get(Clock::class));
        self::assertInstanceOf(Environment::class, $container->get(Environment::class));
    }

    public function testTwigAutoescapesHtml(): void
    {
        $factory = require __DIR__ . '/../../../config/container.php';
        $twig = $factory()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $twig->setLoader(new ArrayLoader(['t' => '{{ v }}']));
        self::assertSame('&lt;script&gt;', $twig->render('t', ['v' => '<script>']));
    }
}
