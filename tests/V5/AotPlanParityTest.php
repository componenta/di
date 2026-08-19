<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Env;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;

final class InvalidAotComposition
{
    public function __construct(
        #[ConfigAttribute('value'), Env('VALUE')]
        public string $value,
    ) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AotExtensionValue
{
    public function __construct(public string $value) {}
}

final readonly class AotExtensionParameterResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'convention';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $target->name === 'convention'
            ? [$target->position, 'resolver-ok']
            : null;
    }
}

final class AotExtensionTarget
{
    public function __construct(public string $convention) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AotHandledParameter
{
    public function __construct(public string $value) {}
}

final readonly class AotHandledParameterHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$attribute instanceof AotHandledParameter) {
            throw new LogicException('Unexpected parameter attribute.');
        }

        return $value->resolved
            ? $value
            : ParameterAttributeValue::resolved($attribute->value);
    }
}

final class AotHandledParameterTarget
{
    public function __construct(
        #[AotHandledParameter('attribute-handler-ok')]
        public string $value,
    ) {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final readonly class AotObjectExtension
{
    public function __construct(public string $label) {}
}

#[AotObjectExtension('class')]
final class AotObjectExtensionTarget
{
    /** @var list<string> */
    public array $events = [];

    #[AotObjectExtension('property')]
    public string $value = '';

    #[AotObjectExtension('method')]
    public function markMethod(): void
    {
        $this->events[] = 'method';
    }
}

final readonly class AotObjectExtensionHandler implements AttributeHandlerInterface
{
    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof AotObjectExtension) {
            throw new LogicException('Unexpected AOT object extension attribute.');
        }

        $entry = $context->entry ?? throw new LogicException('Object must be initialized.');
        if (!$entry instanceof AotObjectExtensionTarget) {
            throw new LogicException('Unexpected AOT object extension target.');
        }

        if ($target instanceof ReflectionClass) {
            $entry->events[] = $attribute->label;
            return;
        }

        if ($target instanceof ReflectionProperty) {
            if ($context->claimProperty($target, allowPromoted: true)) {
                $context->writeProperty($target, $attribute->label);
            }
            return;
        }

        if ($target instanceof ReflectionMethod) {
            $target->invoke($entry);
            return;
        }

        throw new LogicException('Unsupported AOT object extension reflector.');
    }
}

function productionBuilderFromCompiled(
    ContainerBuilder $builder,
    array $compiled,
    string $directory,
): ContainerBuilder {
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
    );
}

function aotExtensionBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addParameterResolver(new AotExtensionParameterResolver(), 750);
}

function aotHandledParameterBuilder(int $definitionVersion = 1): ContainerBuilder
{
    return (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        AotHandledParameter::class,
        new AotHandledParameterHandler(),
        capabilities: [ValueProvider::class],
        version: $definitionVersion,
    ));
}

function aotObjectExtensionBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        AotObjectExtension::class,
        new AotObjectExtensionHandler(),
        version: 1,
    ));
}

test('AOT compilation rejects invalid attribute composition before writing a shard', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-invalid-aot-' . bin2hex(random_bytes(5));

    try {
        expect(fn() => (new ContainerBuilder())->compileFactories(
            [InvalidAotComposition::class],
            $directory,
        ))->toThrow(AttributeCompositionException::class);

        expect(is_dir($directory) ? (glob($directory . '/*') ?: []) : [])->toBe([]);
    } finally {
        cleanupDirectory($directory);
    }
});

test('custom convention parameter resolvers execute identically in development and compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-extension-aot-' . bin2hex(random_bytes(5));
    $builder = aotExtensionBuilder();
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([AotExtensionTarget::class], $directory);
        $production = productionBuilderFromCompiled($builder, $compiled, $directory)->build();

        expect($development->make(AotExtensionTarget::class)->convention)->toBe('resolver-ok')
            ->and($production->make(AotExtensionTarget::class)->convention)->toBe('resolver-ok');
    } finally {
        cleanupDirectory($directory);
    }
});

test('custom parameter attributes execute through the shared attribute resolver in development and compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-attribute-parameter-aot-' . bin2hex(random_bytes(5));
    $builder = aotHandledParameterBuilder();
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([AotHandledParameterTarget::class], $directory);
        $production = productionBuilderFromCompiled($builder, $compiled, $directory)->build();

        expect($development->make(AotHandledParameterTarget::class)->value)->toBe('attribute-handler-ok')
            ->and($production->make(AotHandledParameterTarget::class)->value)->toBe('attribute-handler-ok');
    } finally {
        cleanupDirectory($directory);
    }
});

test('custom class property and method handlers execute identically in development and compiled production', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-object-extension-aot-' . bin2hex(random_bytes(5));
    $builder = aotObjectExtensionBuilder();
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([AotObjectExtensionTarget::class], $directory);
        $production = productionBuilderFromCompiled($builder, $compiled, $directory)->build();

        $developmentEntry = $development->make(AotObjectExtensionTarget::class);
        $productionEntry = $production->make(AotObjectExtensionTarget::class);

        expect([$developmentEntry->events, $developmentEntry->value])
            ->toBe([['class', 'method'], 'property'])
            ->and([$productionEntry->events, $productionEntry->value])
            ->toBe([$developmentEntry->events, $developmentEntry->value]);
    } finally {
        cleanupDirectory($directory);
    }
});

test('compiled production rejects stale shards when parameter attribute semantics change', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-extension-fingerprint-' . bin2hex(random_bytes(5));
    $compilerBuilder = aotHandledParameterBuilder(1);

    try {
        $compiled = $compilerBuilder->compileFactories([AotHandledParameterTarget::class], $directory);
        $runtimeBuilder = aotHandledParameterBuilder(2);
        $production = productionBuilderFromCompiled($runtimeBuilder, $compiled, $directory)->build();

        expect(fn() => $production->make(AotHandledParameterTarget::class))
            ->toThrow(InvalidConfigurationException::class, 'semantic fingerprint');
    } finally {
        cleanupDirectory($directory);
    }
});
