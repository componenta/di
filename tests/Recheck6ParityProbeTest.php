<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;

#[Lazy]
final class Recheck6PrivateLazyConstructor
{
    public bool $initialized;

    private function __construct()
    {
        $this->initialized = true;
    }
}

final class Recheck6LazyDependency
{
}

#[Lazy]
final class Recheck6PrivateLazyConstructorWithDependency
{
    public bool $initialized;

    private function __construct(
        public Recheck6LazyDependency $dependency,
    ) {
        $this->initialized = true;
    }
}

final class Recheck6RequiredInvokableDependency
{
    public function __construct(
        public Recheck6LazyDependency $dependency,
    ) {}
}

final class Recheck6UnionConstructor
{
    public function __construct(
        public Countable|IteratorAggregate $value,
    ) {}
}

final class Recheck6IntersectionConstructor
{
    public function __construct(
        public Countable&IteratorAggregate $value,
    ) {}
}

final class Recheck6NullableDefaultConstructor
{
    public function __construct(
        public ?string $value = 'fallback',
    ) {}
}

/** @return array{0: Componenta\DI\Container, 1: string} */
function recheck6CompiledContainer(array $classes): array
{
    $directory = sys_get_temp_dir() . '/componenta-di-recheck6-' . bin2hex(random_bytes(5));
    $factories = (new ContainerBuilder())->compileFactories($classes, $directory);
    $container = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
        ],
        $directory,
    )->build();

    return [$container, $directory];
}

function recheck6Cleanup(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

it('keeps private lazy constructor behavior between reflection and compiled factories', function (): void {
    $reflection = (new ContainerBuilder())->build();
    [$compiled, $directory] = recheck6CompiledContainer([Recheck6PrivateLazyConstructor::class]);

    try {
        $reflected = $reflection->make(Recheck6PrivateLazyConstructor::class);
        $generated = $compiled->make(Recheck6PrivateLazyConstructor::class);

        expect($reflected->initialized)->toBeTrue()
            ->and($generated->initialized)->toBeTrue();
    } finally {
        recheck6Cleanup($directory);
    }
});

it('keeps private lazy constructors with dependencies between reflection and compiled factories', function (): void {
    $reflection = (new ContainerBuilder())->build();
    [$compiled, $directory] = recheck6CompiledContainer([
        Recheck6PrivateLazyConstructorWithDependency::class,
        Recheck6LazyDependency::class,
    ]);

    try {
        $reflected = $reflection->make(Recheck6PrivateLazyConstructorWithDependency::class);
        $generated = $compiled->make(Recheck6PrivateLazyConstructorWithDependency::class);

        expect($reflected->initialized)->toBeTrue()
            ->and($reflected->dependency)->toBeInstanceOf(Recheck6LazyDependency::class)
            ->and($generated->initialized)->toBeTrue()
            ->and($generated->dependency)->toBeInstanceOf(Recheck6LazyDependency::class);
    } finally {
        recheck6Cleanup($directory);
    }
});

it('keeps a previous factory definition when an invalid invokable replacement is rejected', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('service', Definition::factory(static fn() => new stdClass()));

    expect($container->make('service'))->toBeInstanceOf(stdClass::class);

    try {
        $container->set('service', Definition::invokable(Recheck6RequiredInvokableDependency::class));
        throw new RuntimeException('Expected invalid invokable replacement to be rejected.');
    } catch (InvalidConfigurationException) {
        // Expected.
    }

    expect($container->make('service'))->toBeInstanceOf(stdClass::class);
});

it('keeps union explicit overrides between reflection and compiled factories', function (): void {
    $value = new ArrayObject();
    $reflection = (new ContainerBuilder())->build();
    [$compiled, $directory] = recheck6CompiledContainer([Recheck6UnionConstructor::class]);

    try {
        expect($reflection->make(Recheck6UnionConstructor::class, ['value' => $value])->value)->toBe($value)
            ->and($compiled->make(Recheck6UnionConstructor::class, ['value' => $value])->value)->toBe($value);
    } finally {
        recheck6Cleanup($directory);
    }
});

it('keeps intersection explicit overrides between reflection and compiled factories', function (): void {
    $value = new ArrayObject();
    $reflection = (new ContainerBuilder())->build();
    [$compiled, $directory] = recheck6CompiledContainer([Recheck6IntersectionConstructor::class]);

    try {
        expect($reflection->make(Recheck6IntersectionConstructor::class, ['value' => $value])->value)->toBe($value)
            ->and($compiled->make(Recheck6IntersectionConstructor::class, ['value' => $value])->value)->toBe($value);
    } finally {
        recheck6Cleanup($directory);
    }
});

it('keeps nullable declared defaults between reflection and compiled factories', function (): void {
    $reflection = (new ContainerBuilder())->build();
    [$compiled, $directory] = recheck6CompiledContainer([Recheck6NullableDefaultConstructor::class]);

    try {
        expect($reflection->make(Recheck6NullableDefaultConstructor::class)->value)->toBe('fallback')
            ->and($compiled->make(Recheck6NullableDefaultConstructor::class)->value)->toBe('fallback')
            ->and($reflection->make(Recheck6NullableDefaultConstructor::class, ['value' => null])->value)->toBeNull()
            ->and($compiled->make(Recheck6NullableDefaultConstructor::class, ['value' => null])->value)->toBeNull();
    } finally {
        recheck6Cleanup($directory);
    }
});
