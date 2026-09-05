<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class DeferredValue {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class DeferredAutowiredValue {}

final readonly class DeferredValueResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(DeferredValue::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target)
            ? [$target->position, 'from-service-method']
            : null;
    }
}

final readonly class DeferredConventionResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'fallback';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target)
            ? [$target->position, 'from-deferred-resolver']
            : null;
    }
}

final readonly class DeferredExtensionFactoryService
{
    public function attribute(ContainerInterface $container): AttributeDefinition
    {
        return new AttributeDefinition(
            DeferredValue::class,
            handler: null,
            capabilities: [ValueProvider::class],
        );
    }

    public function valueResolver(ContainerInterface $container): ParameterResolverInterface
    {
        return new DeferredValueResolver();
    }

    public function conventionResolver(ContainerInterface $container): ParameterResolverInterface
    {
        return new DeferredConventionResolver();
    }
}

final readonly class DeferredAutowiredDependency {}

final readonly class DeferredAutowiredAttributeFactory
{
    public function __construct(public DeferredAutowiredDependency $dependency) {}

    public function attribute(ContainerInterface $container): AttributeDefinition
    {
        return new AttributeDefinition(DeferredAutowiredValue::class);
    }
}

final readonly class DeferredExtensionDto
{
    public function __construct(
        #[DeferredValue]
        public string $value,
        public string $fallback,
    ) {}
}

final readonly class DeferredClosureDto
{
    public function __construct(
        #[DeferredValue]
        public string $value,
    ) {}
}

function deferredFunctionResolverFactory(ContainerInterface $container): ParameterResolverInterface
{
    return new DeferredValueResolver();
}

test('container factory materializes deferred attribute and parameter resolver service methods', function (): void {
    $container = (new ContainerBuilder())
        ->addService(DeferredExtensionFactoryService::class, new DeferredExtensionFactoryService())
        ->addAttributeDefinition([DeferredExtensionFactoryService::class, 'attribute'])
        ->addParameterResolver([DeferredExtensionFactoryService::class, 'valueResolver'], 750)
        ->addParameterResolver([DeferredExtensionFactoryService::class, 'conventionResolver'], 350)
        ->build();

    $dto = $container->make(DeferredExtensionDto::class);

    expect($dto->value)->toBe('from-service-method')
        ->and($dto->fallback)->toBe('from-deferred-resolver');
});

test('deferred extension factories can use constructor autowiring during bootstrap', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition([DeferredAutowiredAttributeFactory::class, 'attribute'])
        ->build();

    $factory = $container->get(DeferredAutowiredAttributeFactory::class);

    expect($factory)->toBeInstanceOf(DeferredAutowiredAttributeFactory::class)
        ->and($factory->dependency)->toBeInstanceOf(DeferredAutowiredDependency::class);
});

test('container factory accepts deferred extension service method forms', function (): void {
    $config = new Config([], new Environment([]));
    $dependencies = new DependencyDefinitions([
        ConfigKey::SERVICES => [
            DeferredExtensionFactoryService::class => new DeferredExtensionFactoryService(),
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            [DeferredExtensionFactoryService::class, 'attribute'],
        ],
        ConfigKey::PARAMETER_RESOLVERS => [
            750 => [DeferredExtensionFactoryService::class, 'valueResolver'],
            350 => [DeferredExtensionFactoryService::class, 'conventionResolver'],
        ],
    ]);

    $container = (new ContainerFactory())->create($config, $dependencies)->container;
    if (!$container instanceof Container) {
        throw new \LogicException('ContainerFactory returned an unsupported container implementation.');
    }
    $dto = $container->make(DeferredExtensionDto::class);

    expect($dto->value)->toBe('from-service-method')
        ->and($dto->fallback)->toBe('from-deferred-resolver');
});

test('container factory materializes closure function and service-id extension forms', function (): void {
    $config = new Config([], new Environment([]));
    $dependencies = new DependencyDefinitions([
        ConfigKey::SERVICES => [
            'deferred.resolver.service' => new DeferredValueResolver(),
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static fn(ContainerInterface $container): AttributeDefinition => new AttributeDefinition(
                DeferredValue::class,
                capabilities: [ValueProvider::class],
            ),
        ],
        ConfigKey::PARAMETER_RESOLVERS => [
            750 => __NAMESPACE__ . '\\deferredFunctionResolverFactory',
            700 => 'deferred.resolver.service',
        ],
    ]);

    $container = (new ContainerFactory())->create($config, $dependencies)->container;
    if (!$container instanceof Container) {
        throw new \LogicException('ContainerFactory returned an unsupported container implementation.');
    }

    expect($container->make(DeferredClosureDto::class)->value)->toBe('from-service-method');
});

test(
    'container factory rejects invalid deferred extension results at construction',
    function (
        array $sections,
        string $message,
    ): void {
        $namedSections = [];
        foreach ($sections as $key => $value) {
            if (!is_string($key)) {
                throw new \LogicException('Expected dependency section names to be strings.');
            }
            $namedSections[$key] = $value;
        }

        expect(fn() => (new ContainerFactory())->create(
            new Config([], new Environment([])),
            new DependencyDefinitions($namedSections),
        ))->toThrow(\Componenta\DI\Exception\InvalidConfigurationException::class, $message);
    },
)->with([
    'resolver factory returns wrong object' => [[
        ConfigKey::PARAMETER_RESOLVERS => [
            100 => static fn(ContainerInterface $container): object => new \stdClass(),
        ],
    ], 'Expected ' . ParameterResolverInterface::class],
    'attribute factory returns wrong object' => [[
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static fn(ContainerInterface $container): object => new \stdClass(),
        ],
    ], 'Attribute definition factory returned stdClass'],
    'extension factory returns scalar' => [[
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static fn(ContainerInterface $container): int => 42,
        ],
    ], 'Extension factory returned int instead of an object'],
    'extension service method is not callable' => [[
        ConfigKey::SERVICES => [
            'extension.factory' => new \stdClass(),
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            ['extension.factory', 'create'],
        ],
    ], 'Extension service method "extension.factory::create" is not callable'],
]);
