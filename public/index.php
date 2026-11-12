<?php

declare(strict_types=1);

use App\Shared\Http\Kernel;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

$container = (require __DIR__ . '/../config/container.php')();
$kernel = $container->get(Kernel::class);
assert($kernel instanceof Kernel);

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

(new SapiEmitter())->emit($kernel->handle($request));
