<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableResolver;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Entry\ConfiguredFactoryDefinition;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver as EntryFactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Parameter\ArrayResolver as ParameterArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Composes one container and owns publication of its runtime.
 *
 * @internal
 */
final class ContainerAssembly
{
    private const int PRIORITY_PARAM_ATTRIBUTE = 1200;
    private const int PRIORITY_PARAM_ARRAY = 1100;
    private const int PRIORITY_PARAM_ARRAY_TYPED = 1000;
    private const int PRIORITY_PARAM_AUTOWIRE = 300;
    private const int PRIORITY_PARAM_DEFAULT_VALUE = 200;
    private const int PRIORITY_PARAM_NULLABLE = 100;

    /** @var array<string,non-empty-string> */
    private const array DEFAULT_ALIASES = [];

    /** @var array<string,mixed> */
    private array $factories = [];
    /** @var list<class-string> */
    private array $invokables = [];
    /** @var array<string,non-empty-string> */
    private array $aliases = self::DEFAULT_ALIASES;
    private AliasResolver $aliasResolver;
    /** @var array<string,list<callable|string|array{object|string,string}>> */
    private array $delegators = [];
    /** @var array<string,mixed> */
    private array $services = [];
    /** @var list<array{0:mixed,1:int}> */
    private array $parameterResolvers = [];
    /** @var list<mixed> */
    private array $attributeDefinitions = [];
    /** @var list<CapabilityPolicy> */
    private array $attributeCapabilities = [];
    private bool $replaceParameterResolvers = false;
    private bool $replaceAttributeDefinitions = false;
    private Config $config;

    private function __construct() {}

    public static function assemble(
        Config $config,
        DependencyDefinitions $dependencies,
        bool $targetedDiscovery = false,
    ): ContainerValue {
        return self::build($config, $dependencies, $targetedDiscovery, null);
    }

    public static function assembleWithRuntime(
        Config $config,
        DependencyDefinitions $dependencies,
        ResolutionRuntime $runtime,
    ): ContainerValue {
        return self::build($config, $dependencies, false, $runtime);
    }

    private static function build(
        Config $config,
        DependencyDefinitions $dependencies,
        bool $targetedDiscovery,
        ?ResolutionRuntime $runtime,
    ): ContainerValue {
        try {
            $assembly = self::configured($config, $dependencies);
            $aliases = $assembly->assertBindings();
            $runtime ??= new ResolutionRuntime($targetedDiscovery);
            $runtime->claim();
            $container = $assembly->buildContainer($aliases, $runtime);
            $runtime->bootstrapGuard->finish(static function () use (&$assembly): void {
                $assembly = null;
            });

            return new ContainerValue($container, $config);
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to build DI container: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    private static function configured(
        Config $config,
        DependencyDefinitions $definitions,
    ): self {
        [$dependencies, $aliasGraph] = DependencyNormalizer::normalizeWithAliasGraph(
            $definitions->sections,
            self::DEFAULT_ALIASES,
        );
        $factory = new self();

        $factory->factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        $factory->invokables = $dependencies[ConfigKey::INVOKABLES] ?? [];
        $factory->aliases = $dependencies[ConfigKey::ALIASES] ?? self::DEFAULT_ALIASES;
        $factory->aliasResolver = AliasResolver::fromGraph($aliasGraph);
        $factory->delegators = $dependencies[ConfigKey::DELEGATORS] ?? [];
        $factory->services = $dependencies[ConfigKey::SERVICES] ?? [];

        foreach ($dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [] as $priority => $resolver) {
            $factory->parameterResolvers[] = [$resolver, $priority];
        }

        $factory->replaceParameterResolvers = $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false;
        $factory->attributeDefinitions = array_values($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS] ?? []);
        $factory->replaceAttributeDefinitions = $dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE] ?? false;
        $factory->attributeCapabilities = array_values($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? []);
        $factory->config = $config;

        return $factory;
    }

    private function buildContainer(AliasResolver $aliases, ResolutionRuntime $runtime): Container
    {
        $config = $this->config;
        $attributes = $runtime->attributes;
        $plans = $runtime->plans;
        $parameters = $runtime->parameters;
        $proxyFactory = $runtime->proxies;
        $objects = $runtime->objects;

        $aliases->set(ConfigAttribute::KEY, Config::class);
        $cache = new EntryCache();
        foreach ($this->services as $id => $service) {
            $cache->putBase($aliases->resolve($id), $service);
        }

        $bootstrap = new ContainerBootstrapState();
        $container = ContainerConstruction::defer($bootstrap, $aliases, $cache, $proxyFactory, $config);

        $executor = new CallableExecutor(new CallableResolver($container), $parameters);
        $entryResolver = $this->createEntryResolver(
            $container,
            $proxyFactory,
            $objects,
        );
        $bootstrap->initialize($entryResolver, $executor);

        $attributeCallbacks = new AttributeCallbackInvoker($parameters, $container);
        $handlers = BuiltInAttributes::handlers($container, $proxyFactory, $plans, $attributeCallbacks);
        $cache->putBase(RequestAttributeHandler::class, $handlers['request']);

        if (!$this->replaceAttributeDefinitions) {
            BuiltInAttributes::register(
                $attributes,
                $container,
                $config,
                $handlers,
                $objects,
                $attributeCallbacks,
            );
        }

        foreach ($this->attributeCapabilities as $policy) {
            $attributes->defineCapability($policy);
        }

        if (!$this->replaceParameterResolvers) {
            foreach ($this->defaultParameterResolvers($container, $plans) as [$resolver, $priority]) {
                $parameters->add($resolver, $priority);
            }
        }

        foreach ($this->delegators as $id => $items) {
            foreach ($items as $delegator) {
                $container->delegator($id, $delegator);
            }
        }

        foreach ($this->attributeDefinitions as $spec) {
            $attributes->register(ExtensionFactory::attributeDefinition($spec, $container));
        }

        foreach ($this->parameterResolvers as [$spec, $priority]) {
            $parameters->add(ExtensionFactory::parameterResolver($spec, $container), $priority);
        }

        $parameters->seal();
        $attributes->seal();
        $container->get(Config::class);

        return $container;
    }

    private function createEntryResolver(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ObjectPipeline $objects,
    ): EntryResolverInterface {
        $resolver = new CompositeResolver(
            new EntryFactoryResolver(
                $container,
                $proxyFactory,
            ),
            new InvokableResolver(),
            new ReflectionResolver($objects, ProtectedServiceIds::autowiringExcluded()),
        );
        foreach ($this->factories as $id => $factory) {
            $resolver->setDefinition(
                $id,
                $factory instanceof DefinitionInterface ? $factory : new ConfiguredFactoryDefinition($factory),
            );
        }
        foreach ($this->invokables as $class) {
            $resolver->setDefinition($class, new InvokableDefinition($class));
        }
        return $resolver;
    }

    /** @return list<array{0:ParameterResolverInterface,1:int}> */
    private function defaultParameterResolvers(
        ContainerInterface $container,
        AttributePlanBuilder $plans,
    ): array {
        return [
            [new AttributeParameterResolver($plans), self::PRIORITY_PARAM_ATTRIBUTE],
            [new ParameterArrayResolver(), self::PRIORITY_PARAM_ARRAY],
            [new ArrayTypedResolver(), self::PRIORITY_PARAM_ARRAY_TYPED],
            [new AutowireByTypeResolver($container), self::PRIORITY_PARAM_AUTOWIRE],
            [new DefaultValueResolver(), self::PRIORITY_PARAM_DEFAULT_VALUE],
            [new NullableResolver(), self::PRIORITY_PARAM_NULLABLE],
        ];
    }

    private function assertBindings(): AliasResolver
    {
        $aliases = $this->aliasResolver;
        /** @var array<string,array{kind:string,id:string}> $owners */
        $owners = [];

        foreach ([
            'factory' => array_keys($this->factories),
            'invokable' => $this->invokables,
            'service' => array_keys($this->services),
        ] as $kind => $ids) {
            foreach ($ids as $id) {
                self::assertId($id, $kind);

                if (($kind === 'factory' || $kind === 'invokable') && $aliases->has($id)) {
                    throw new InvalidConfigurationException(sprintf(
                        '%s id "%s" is also an alias and would be unreachable after canonicalization.',
                        ucfirst($kind),
                        $id,
                    ));
                }

                $canonical = $aliases->resolve($id);
                if (ProtectedServiceIds::contains($canonical)) {
                    throw new InvalidConfigurationException(sprintf(
                        'Cannot register %s for id "%s" because it resolves to protected DI id "%s".',
                        $kind,
                        $id,
                        $canonical,
                    ));
                }

                $owner = $owners[$canonical] ?? null;
                if ($owner !== null) {
                    throw new InvalidConfigurationException(sprintf(
                        'Canonical DI id "%s" has multiple bindings: %s "%s" and %s "%s".',
                        $canonical,
                        $owner['kind'],
                        $owner['id'],
                        $kind,
                        $id,
                    ));
                }
                $owners[$canonical] = ['kind' => $kind, 'id' => $id];
            }
        }

        foreach (array_keys($this->delegators) as $id) {
            self::assertId($id, 'delegator');
            $canonical = $aliases->resolve($id);
            if (ProtectedServiceIds::contains($canonical)) {
                throw new InvalidConfigurationException(sprintf(
                    'Cannot register delegator for id "%s" because it resolves to protected DI id "%s".',
                    $id,
                    $canonical,
                ));
            }
        }

        foreach (array_keys($this->aliases) as $alias) {
            self::assertId($alias, 'alias');
        }
        return $aliases;
    }

    private static function assertId(string $id, string $kind): void
    {
        if ($id === '') {
            throw new InvalidConfigurationException(sprintf(
                '%s id must be non-empty.',
                ucfirst($kind),
            ));
        }
        if (ProtectedServiceIds::contains($id)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot register %s for protected DI id "%s".',
                $kind,
                $id,
            ));
        }
    }

}
