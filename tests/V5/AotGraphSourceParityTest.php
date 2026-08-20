<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Reflector;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AotGraphSource {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AotGraphCapabilityOnly {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AotGraphSkipConstructor {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AotGraphCapabilityOnlyConstructorPolicy {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AotGraphLateConstructorPolicy {}

final class AotGraphNonAutowireDependency
{
    public function __construct(
        #[ConfigAttribute('value'), Env('VALUE')]
        public string $value,
    ) {}
}

final readonly class AotGraphNormalDependency {}

final readonly class AotGraphSourceRoot
{
    public function __construct(
        #[AotGraphSource]
        public AotGraphNonAutowireDependency $dependency,
    ) {}
}

final readonly class AotGraphCapabilityOnlyRoot
{
    public function __construct(
        #[AotGraphCapabilityOnly]
        public AotGraphNormalDependency $dependency,
    ) {}
}

#[AotGraphSkipConstructor]
final class AotGraphConstructorPolicyRoot
{
    public function __construct(AotGraphNonAutowireDependency $dependency)
    {
        unset($dependency);
        throw new LogicException('Constructor policy must disable this constructor.');
    }
}

#[AotGraphCapabilityOnlyConstructorPolicy]
abstract class AotGraphCapabilityOnlyConstructorTarget {}

#[AotGraphLateConstructorPolicy]
abstract class AotGraphLateConstructorPolicyTarget {}

final readonly class AotGraphSourceHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        $reflection = new ReflectionClass(AotGraphNonAutowireDependency::class);

        return ParameterAttributeValue::resolved($reflection->newInstanceWithoutConstructor());
    }
}

final readonly class AotGraphSkipConstructorHandler implements AttributeHandlerInterface
{
    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof AotGraphSkipConstructor || !$target instanceof ReflectionClass) {
            throw new LogicException('Unexpected constructor-policy target.');
        }

        $context->disableConstructor();
    }
}

final readonly class AotGraphLateConstructorPolicyHandler implements AttributeHandlerInterface
{
    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof AotGraphLateConstructorPolicy || !$target instanceof ReflectionClass) {
            throw new LogicException('Unexpected late constructor-policy target.');
        }

        $context->disableConstructor();
    }
}

function cleanupAotGraphDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}

/**
 * @param array<class-string,\Componenta\DI\Compile\Factory\CompiledFactoryDefinition> $compiled
 */
function aotGraphProductionContainer(
    ContainerBuilder $builder,
    array $compiled,
    string $directory,
): \Componenta\DI\Container {
    $data = $builder->toArray();
    $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $compiled,
    );

    return ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
        $directory,
    )->build();
}

test('runtime value providers preserve the same dependency behavior in development and compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-aot-source-' . bin2hex(random_bytes(5));
    $builder = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        AotGraphSource::class,
        new AotGraphSourceHandler(),
        capabilities: [ValueProvider::class],
    ));

    try {
        $development = $builder->build();
        $compiled = $builder->compileFactories([AotGraphSourceRoot::class], $directory);
        $production = aotGraphProductionContainer($builder, $compiled, $directory);

        expect($development->make(AotGraphSourceRoot::class)->dependency)
            ->toBeInstanceOf(AotGraphNonAutowireDependency::class)
            ->and($production->make(AotGraphSourceRoot::class)->dependency)
            ->toBeInstanceOf(AotGraphNonAutowireDependency::class);
    } finally {
        cleanupAotGraphDirectory($directory);
    }
});

test('capability-only parameter metadata keeps normal autowiring in development and compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-aot-capability-only-' . bin2hex(random_bytes(5));
    $builder = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        AotGraphCapabilityOnly::class,
        handler: null,
        capabilities: [ValueProvider::class],
    ));

    try {
        $development = $builder->build();
        $compiled = $builder->compileFactories([AotGraphCapabilityOnlyRoot::class], $directory);
        $production = aotGraphProductionContainer($builder, $compiled, $directory);

        expect($development->make(AotGraphCapabilityOnlyRoot::class)->dependency)
            ->toBeInstanceOf(AotGraphNormalDependency::class)
            ->and($production->make(AotGraphCapabilityOnlyRoot::class)->dependency)
            ->toBeInstanceOf(AotGraphNormalDependency::class);
    } finally {
        cleanupAotGraphDirectory($directory);
    }
});

test('custom constructor policies have identical development and compiled production semantics', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-aot-constructor-policy-' . bin2hex(random_bytes(5));
    $builder = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        AotGraphSkipConstructor::class,
        new AotGraphSkipConstructorHandler(),
        capabilities: [ConstructorPolicy::class],
        phase: AttributePhase::BeforeInstantiation,
    ));

    try {
        $development = $builder->build();
        $compiled = $builder->compileFactories([AotGraphConstructorPolicyRoot::class], $directory);
        $production = aotGraphProductionContainer($builder, $compiled, $directory);

        expect($development->make(AotGraphConstructorPolicyRoot::class))
            ->toBeInstanceOf(AotGraphConstructorPolicyRoot::class)
            ->and($production->make(AotGraphConstructorPolicyRoot::class))
            ->toBeInstanceOf(AotGraphConstructorPolicyRoot::class);
    } finally {
        cleanupAotGraphDirectory($directory);
    }
});

test('constructor policy must execute before instantiation to make a non-instantiable entry resolvable', function (): void {
    $capabilityOnly = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AotGraphCapabilityOnlyConstructorPolicy::class,
            handler: null,
            capabilities: [ConstructorPolicy::class],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->build();

    expect($capabilityOnly->has(AotGraphCapabilityOnlyConstructorTarget::class))->toBeFalse();
    expect(fn(): mixed => $capabilityOnly->get(AotGraphCapabilityOnlyConstructorTarget::class))
        ->toThrow(NotFoundExceptionInterface::class);

    $late = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AotGraphLateConstructorPolicy::class,
            new AotGraphLateConstructorPolicyHandler(),
            capabilities: [ConstructorPolicy::class],
            phase: AttributePhase::AfterInstantiation,
        ))
        ->build();

    expect($late->has(AotGraphLateConstructorPolicyTarget::class))->toBeFalse();
    expect(fn(): mixed => $late->get(AotGraphLateConstructorPolicyTarget::class))
        ->toThrow(NotFoundExceptionInterface::class);
});
