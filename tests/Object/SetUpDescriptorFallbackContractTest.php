<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\SetUpDescriptorFallbackContract;

use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;

final class RequiredSetUpDescriptorCalls
{
    public int $count = 0;
}

final class MissingSetUpMethodCalls
{
    public int $constructors = 0;
    public int $magicCalls = 0;
}

#[SetUp('missing')]
final class MissingSetUpMethodTarget
{
    public function __construct(private readonly MissingSetUpMethodCalls $calls)
    {
        ++$this->calls->constructors;
    }

    /** @param array<array-key,mixed> $arguments */
    public function __call(string $name, array $arguments): void
    {
        ++$this->calls->magicCalls;
    }
}

test('SetUp rejects missing declared methods before magic dispatch and never shares failed objects', function (): void {
    $calls = new MissingSetUpMethodCalls();
    $container = (new ContainerBuilder())->addService(MissingSetUpMethodCalls::class, $calls)->build();

    foreach ([1, 2] as $attempt) {
        $failure = null;
        try {
            $container->get(MissingSetUpMethodTarget::class);
        } catch (ResolutionException $actual) {
            $failure = $actual;
        }
        if (!$failure instanceof ResolutionException) {
            throw new \LogicException('An absent declared setup method must reject object creation.');
        }
        $previous = $failure->getPrevious();
        if (!$previous instanceof \LogicException) {
            throw new \LogicException('The setup diagnostic must retain the missing method cause.', previous: $failure);
        }
        expect($previous->getMessage())->toBe('SetUp method "' . MissingSetUpMethodTarget::class . '::missing()" does not exist.')
            ->and($calls->constructors)->toBe($attempt)
            ->and($calls->magicCalls)->toBe(0);
    }
});

abstract class RequiredSetUpDescriptorTarget
{
    public float $ratio = 0.0;

    public function configure(float $ratio, RequiredSetUpDescriptorCalls $calls): void
    {
        ++$calls->count;
        $this->ratio = $ratio;
    }
}

#[SetUp('configure', ['ratio' => new ConfigAttribute(new ConfigPath('required.ratio'))])]
final class RequiredConfigSetUpTarget extends RequiredSetUpDescriptorTarget {}

#[SetUp('configure', ['ratio' => new Env('REQUIRED_RATIO')])]
final class RequiredEnvironmentSetUpTarget extends RequiredSetUpDescriptorTarget {}

#[SetUp('configure', ['port' => new Env('MISSING_PORT', default: 8123)])]
final class SetUpEnvironmentFallbackTarget
{
    public int $port = 0;
    public int $calls = 0;

    public function configure(int $port): void
    {
        $this->port = $port;
        ++$this->calls;
    }
}

#[SetUp('configure', [
    'name' => new ConfigAttribute(new ConfigPath('settings.name')),
    'fallback' => new ConfigAttribute(new ConfigPath('settings.missing'), default: 'descriptor default'),
])]
final class SetUpConfigurationPathTarget
{
    public string $name = '';
    public string $fallback = '';
    public int $calls = 0;

    public function configure(string $name, string $fallback): void
    {
        $this->name = $name;
        $this->fallback = $fallback;
        ++$this->calls;
    }
}

test('SetUp uses an Env descriptor default when the environment variable is absent', function (): void {
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;

    if (!$container instanceof Container) {
        throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
    }

    $entry = $container->make(SetUpEnvironmentFallbackTarget::class);
    Assert::assertInstanceOf(SetUpEnvironmentFallbackTarget::class, $entry);

    expect($entry->port)->toBe(8123)
        ->and($entry->calls)->toBe(1);
});

test('SetUp uses explicit Config descriptor paths and their defaults instead of parameter keys', function (): void {
    $container = (new ContainerFactory())->create(
        new Config([
            'name' => 'wrong parameter key',
            'fallback' => 'wrong fallback key',
            'settings' => ['name' => 'configured path'],
        ], new Environment([])),
        new DependencyDefinitions([]),
    )->container;

    if (!$container instanceof Container) {
        throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
    }

    $entry = $container->make(SetUpConfigurationPathTarget::class);
    Assert::assertInstanceOf(SetUpConfigurationPathTarget::class, $entry);

    expect($entry->name)->toBe('configured path')
        ->and($entry->fallback)->toBe('descriptor default')
        ->and($entry->calls)->toBe(1);
});

test('required SetUp descriptors reject absent values before invoking the method and accept present values', function (
    bool $environment,
): void {
    $class = $environment ? RequiredEnvironmentSetUpTarget::class : RequiredConfigSetUpTarget::class;
    $calls = new RequiredSetUpDescriptorCalls();
    $container = (new ContainerBuilder(new Config([], new Environment([]))))
        ->addService(RequiredSetUpDescriptorCalls::class, $calls)
        ->build();
    $error = null;
    try {
        $container->get($class);
    } catch (ResolutionException $caught) {
        $error = $caught;
    }

    if (!$error instanceof ResolutionException) {
        throw new \RuntimeException('A required SetUp descriptor must reject its absent value.');
    }
    expect($error->getMessage())->toContain('Cannot unwrap #[SetUp] param "ratio"', $environment ? '#[Env]' : '#[Config]')
        ->and($calls->count)->toBe(0);
    $previous = $error->getPrevious();
    if ($environment) {
        expect($error->getMessage())->toContain('environment variable "REQUIRED_RATIO" is not defined')
            ->and($previous)->toBeNull();
    } else {
        if (!$previous instanceof ConfigException) {
            throw new \RuntimeException('A missing config descriptor must retain the Config exception.', previous: $error);
        }
        expect($previous->key)->toBe('required.ratio')
            ->and($error->getMessage())->toContain($previous->getMessage());
    }

    $configured = (new ContainerBuilder(new Config(
        ['required' => ['ratio' => 1.25]],
        new Environment(['REQUIRED_RATIO' => '1.25']),
    )))
        ->addService(RequiredSetUpDescriptorCalls::class, $calls)
        ->build();
    $entry = $configured->get($class);
    if (!$entry instanceof RequiredSetUpDescriptorTarget) {
        throw new \RuntimeException('Expected a configured SetUp descriptor target.');
    }
    expect($entry->ratio)->toBe(1.25)
        ->and($calls->count)->toBe(1)
        ->and($configured->get($class))->toBe($entry);
})->with(['Config' => false, 'Env' => true]);
