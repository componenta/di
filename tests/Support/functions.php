<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Internal\ContainerAssembly;
use Componenta\DI\Internal\ResolutionRuntime;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Process\Process;

/** @param array<string, mixed> $sections */
function container(array $sections = [], ?Config $config = null): Container
{
    $value = (new ContainerFactory())->create(
        $config ?? new Config([], new Environment([])),
        new DependencyDefinitions($sections),
    );

    if (!$value->container instanceof Container) {
        throw new RuntimeException('DI factory returned an unsupported container implementation.');
    }

    return $value->container;
}

/**
 * @param array<string, mixed> $sections
 * @return array{Container, ResolutionRuntime}
 */
function assembledContainer(
    array $sections = [],
    ?Config $config = null,
    bool $targetedDiscovery = false,
    ?ResolutionRuntime $runtime = null,
): array {
    $runtime ??= new ResolutionRuntime($targetedDiscovery);
    $value = ContainerAssembly::assembleWithRuntime(
        $config ?? new Config([], new Environment([])),
        new DependencyDefinitions($sections),
        $runtime,
    );

    if (!$value->container instanceof Container) {
        throw new RuntimeException('DI factory returned an unsupported container implementation.');
    }

    return [$value->container, $runtime];
}

/**
 * @template T of object
 * @param class-string<T> $type
 * @return T
 */
function fixtureInstance(mixed $value, string $type): object
{
    Assert::assertInstanceOf($type, $value);
    return $value;
}

/** @return array<array-key, mixed> */
function runContainerFixture(string $fixture, string ...$arguments): array
{
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY,
        '-n',
        $root . '/tests/Fixtures/' . $fixture,
        $root . '/vendor/autoload.php',
        $root . '/src',
        ...$arguments,
    ], timeout: 30.0);
    $process->mustRun();

    Assert::assertSame('', $process->getErrorOutput(), 'Unexpected stderr from ' . $fixture);
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    Assert::assertIsArray($result);

    return $result;
}
