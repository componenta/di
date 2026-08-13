<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\Config\Config;
use Componenta\DI\AliasResolverInterface;
use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Fixture\DelegatorContract;
use Componenta\DI\Tests\Fixture\DelegatorContractImplementation;
use Componenta\DI\Tests\Fixture\PrivateInjectedChild;
use Componenta\DI\Tests\Fixture\PrivateInjectedDependency;
use Componenta\DI\Tests\Fixture\RepeatedTypedConstructor;

test('public alias resolver observes container-managed alias mutations', function () {
    $first = new stdClass();
    $second = new stdClass();
    $container = minimalBuilder()
        ->addService('first', $first)
        ->addService('second', $second)
        ->addAlias('current', 'first')
        ->build();

    $aliases = $container->get(AliasResolverInterface::class);

    expect($aliases)->toBeInstanceOf(AliasResolverInterface::class)
        ->and($aliases->resolve('current'))->toBe('first')
        ->and($container->get('current'))->toBe($first);

    $container->alias('current', 'second');

    expect($aliases->resolve('current'))->toBe('second')
        ->and($container->get('current'))->toBe($second);
});

test('builder accepts an interface instance method as a delegator reference', function () {
    $container = minimalBuilder()
        ->addInvokable(DelegatorContractImplementation::class)
        ->addAlias(DelegatorContract::class, DelegatorContractImplementation::class)
        ->addService('service', 'base')
        ->addDelegator('service', [DelegatorContract::class, 'decorate'])
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('dependency configuration accepts an interface instance method as one delegator reference', function () {
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::DELEGATORS => ['service' => [DelegatorContract::class, 'decorate']]],
    )
        ->addInvokable(DelegatorContractImplementation::class)
        ->addAlias(DelegatorContract::class, DelegatorContractImplementation::class)
        ->addService('service', 'base')
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('builder accepts an opaque service id method as a delegator reference', function () {
    $container = minimalBuilder()
        ->addService('decorator.service', new DelegatorContractImplementation())
        ->addService('service', 'base')
        ->addDelegator('service', ['decorator.service', 'decorate'])
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('dependency configuration accepts a nested opaque service method delegator reference', function () {
    $container = ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::DELEGATORS => [
            'service' => [['decorator.service', 'decorate']],
        ]],
    )
        ->addService('decorator.service', new DelegatorContractImplementation())
        ->addService('service', 'base')
        ->build();

    expect($container->get('service'))->toBe('base:decorated');
});

test('factory configuration rejects a private object method reference at the boundary', function () {
    $factory = new class () {
        private function hidden(): string
        {
            return 'should-not-run';
        }
    };

    expect(fn() => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::FACTORIES => ['service' => [$factory, 'hidden']]],
    ))->toThrow(InvalidConfigurationException::class);
});

test('autowire compilation graph includes dependencies injected into private parent properties', function () {
    $classes = (new AutowireClassGraph())->expand([PrivateInjectedChild::class]);

    expect($classes)
        ->toContain(PrivateInjectedChild::class)
        ->toContain(PrivateInjectedDependency::class);
});

test('factory configuration rejects malformed values using the internal compiled factory marker', function () {
    $malformed = "\0componenta.compiled-factory\0broken";

    expect(fn() => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::FACTORIES => ['service' => $malformed]],
    ))->toThrow(InvalidConfigurationException::class);
});

test('compiled shard cache never reuses a shard for a conflicting declared class', function () {
    $class = 'DeepRecheckCompiledShard_' . bin2hex(random_bytes(6));
    $file = tempnam(sys_get_temp_dir(), 'componenta-di-shard-');

    expect($file)->not->toBeFalse();
    /** @var non-empty-string $file */
    $source = sprintf(
        <<<'PHP'
<?php

final class %s
{
    public function __construct(
        array $parameterResolvers,
        array $attributeHandlers,
        \Componenta\DI\ProxyFactoryInterface $proxyFactory,
    ) {}

    public function create(array $parameters = []): string
    {
        return 'first-shard';
    }
}

return %s::class;
PHP,
        $class,
        $class,
    );
    file_put_contents($file, $source);

    try {
        $runtime = minimalBuilder()->build();
        $parameters = $runtime->get(ParametersResolver::class);
        $attributes = $runtime->get(AttributeProcessor::class);

        expect($parameters)->toBeInstanceOf(ParametersResolver::class)
            ->and($attributes)->toBeInstanceOf(AttributeProcessor::class);

        $resolver = new FactoryResolver(
            [
                'first' => new CompiledFactoryDefinition($file, $class, 'create'),
                'second' => new CompiledFactoryDefinition($file, $class . 'Mismatch', 'create'),
            ],
            $runtime,
            $runtime,
            $parameters,
            $attributes,
        );

        expect($resolver->resolve('first'))->toBe('first-shard');
        expect(fn() => $resolver->resolve('second'))
            ->toThrow(InvalidConfigurationException::class);
    } finally {
        @unlink($file);
    }
});

test('explicit positional objects are not reused for earlier parameters by type', function () {
    $explicitSecond = new DelegatorContractImplementation();
    $container = minimalBuilder()->build();

    [$first, $second] = $container->call(
        static fn(
            DelegatorContractImplementation $first,
            DelegatorContractImplementation $second,
        ): array => [$first, $second],
        [1 => $explicitSecond],
    );

    expect($first)
        ->toBeInstanceOf(DelegatorContractImplementation::class)
        ->not->toBe($explicitSecond)
        ->and($second)->toBe($explicitSecond);
});

test('compiled and reflection factories keep repeated typed positional parameters isolated', function () {
    $directory = sys_get_temp_dir() . '/componenta-deep-recheck-' . bin2hex(random_bytes(5));
    $explicitSecond = new DelegatorContractImplementation();

    try {
        $reflection = minimalBuilder()->build();
        $factories = minimalBuilder()->compileFactories([
            RepeatedTypedConstructor::class,
        ], $directory);
        $compiled = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => $factories],
            ],
            $directory,
        )->build();

        $expected = $reflection->make(RepeatedTypedConstructor::class, [1 => $explicitSecond]);
        $actual = $compiled->make(RepeatedTypedConstructor::class, [1 => $explicitSecond]);

        expect($expected->first)->not->toBe($explicitSecond)
            ->and($expected->second)->toBe($explicitSecond)
            ->and($actual->first)->not->toBe($explicitSecond)
            ->and($actual->second)->toBe($explicitSecond);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});
