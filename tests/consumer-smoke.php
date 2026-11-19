<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\ConsumerSmoke;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use ErrorException;
use ReflectionClass;
use RuntimeException;

// Run independently of the development autoloader and test framework.
// php tests/consumer-smoke.php /path/to/consumer/vendor/autoload.php
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!isset($argv[1]) || !is_file($argv[1])) {
    throw new RuntimeException('Pass the consumer vendor/autoload.php path as the first argument.');
}

$autoload = realpath($argv[1]);
if ($autoload === false) {
    throw new RuntimeException('Cannot resolve the consumer autoloader path.');
}

require $autoload;

$packageSource = dirname($autoload) . '/componenta/di/src';
foreach ([ContainerFactory::class => 'ContainerFactory.php', Container::class => 'Container.php'] as $class => $file) {
    if ((new ReflectionClass($class))->getFileName() !== $packageSource . '/' . $file) {
        throw new RuntimeException('The smoke test must load DI from the consumer vendor directory.');
    }
}

final readonly class Service
{
    public function __construct(public string $label = 'shared') {}
}

$environment = new Environment([]);
$config = new Config([], $environment);
$value = (new ContainerFactory())->create($config, new DependencyDefinitions([]));
$container = $value->container;
if (!$container instanceof Container || $value->config !== $config || $container->get(Environment::class) !== $environment) {
    throw new RuntimeException('ContainerFactory must preserve its configuration and environment.');
}

$shared = $container->get(Service::class);
if (!$shared instanceof Service || $shared->label !== 'shared' || $container->get(Service::class) !== $shared) {
    throw new RuntimeException('get() must autowire and share the service.');
}

$fresh = $container->make(Service::class, ['label' => 'fresh']);
if (!$fresh instanceof Service || $fresh === $shared || $fresh->label !== 'fresh' || $container->get(Service::class) !== $shared) {
    throw new RuntimeException('make() must apply parameters without replacing the shared service.');
}

$called = $container->call(
    static fn(Service $service, string $suffix): array => [$service, $service->label . $suffix],
    ['suffix' => '!'],
);
if ($called !== [$shared, 'shared!']) {
    throw new RuntimeException('call() must combine dependency injection with explicit parameters.');
}

printf("Consumer smoke passed on PHP %s.\n", PHP_VERSION);
