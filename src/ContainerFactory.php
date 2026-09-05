<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Composition\Rule\ProxyCompositionRule;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\BootstrapResolutionGuard;
use Componenta\DI\Internal\ContainerBootstrapState;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\ProtectedServiceIds;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Internal\Resolver\Parameter\Request\LazyCasterProvider;
use Componenta\DI\Internal\Resolver\Parameter\Request\LazyFactory;
use Componenta\DI\Internal\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Attribute\Handler\ConfigHandler;
use Componenta\DI\Resolver\Attribute\Handler\EntryIdHandler;
use Componenta\DI\Resolver\Attribute\Handler\EnvHandler;
use Componenta\DI\Resolver\Attribute\Handler\InitHandler;
use Componenta\DI\Resolver\Attribute\Handler\InjectHandler;
use Componenta\DI\Resolver\Attribute\Handler\LazyHandler;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver as EntryFactoryResolver;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Entry\SetUp\ConfigUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\ContainerValueUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EntryIdUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EnvUnwrapper;
use Componenta\DI\Resolver\Entry\SetUpRunner;
use Componenta\DI\Resolver\Parameter\ArrayResolver as ParameterArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Throwable;

/**
 *
 * Parameter values are produced only by ParameterResolverInterface. Parameter
 * attributes enter that pipeline through one AttributeParameterResolver; class,
 * property and method attributes execute through AttributeHandlerInterface.
 *
 */
final class ContainerFactory
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

    public function __construct() {}

    public function create(
        Config $config,
        DependencyDefinitions $dependencies,
    ): ContainerValue {
        $factory = self::configured($config, $dependencies);
        $container = $factory->build();

        return new ContainerValue($container, $config);
    }

    private static function configured(
        Config $config,
        DependencyDefinitions $definitions,
    ): self {
        $dependencies = DependencyConfiguration::normalize(
            $definitions->sections,
            self::DEFAULT_ALIASES,
        );
        $factory = new self();

        $factory->factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        $factory->invokables = $dependencies[ConfigKey::INVOKABLES] ?? [];
        $factory->aliases = $dependencies[ConfigKey::ALIASES] ?? self::DEFAULT_ALIASES;
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

    private function build(): Container
    {
        try {
            return $this->buildContainer();
        } catch (InvalidConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidConfigurationException(
                sprintf('Failed to build DI container: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function buildContainer(): Container
    {
        $this->assertBindings();

        $config = $this->config;
        $environment = $config->environment;
        $attributes = new AttributeDefinitionRegistry();
        $plans = new AttributePlanBuilder($attributes);
        $bootstrapGuard = new BootstrapResolutionGuard($plans);
        $parameters = new ParametersResolver($plans, bootstrap: $bootstrapGuard);
        $attributeProcessor = new AttributeProcessor($attributes, $plans, $bootstrapGuard);
        $proxyFactory = new ProxyFactory();
        $resolutionParameters = new ObjectResolutionParameterStore();
        $objects = new ObjectPipeline(
            $plans,
            new InstanceCreator($parameters),
            $proxyFactory,
            $attributes,
            $resolutionParameters,
            $attributeProcessor,
        );

        $aliases = new AliasResolver([
            ...$this->aliases,
            ConfigAttribute::KEY => Config::class,
        ]);
        $cache = new EntryCache();
        foreach ($this->services as $id => $service) {
            $cache->putBase($aliases->resolve($id), $service);
        }

        $bootstrap = new ContainerBootstrapState();
        /** @var ReflectionClass<Container> $containerClass */
        $containerClass = new ReflectionClass(Container::class);
        /** @var Container $container */
        $container = $containerClass->newLazyGhost(
            static function (Container $container) use (
                $bootstrap,
                $aliases,
                $cache,
                $proxyFactory,
                $config,
                $environment,
                $attributes,
                $plans,
                $parameters,
                $attributeProcessor,
                $objects,
            ): void {
                $container->__construct(
                    resolver: $bootstrap->entryResolver(),
                    aliases: $aliases,
                    callableExecutor: $bootstrap->callableExecutor(),
                    cache: $cache,
                    proxyFactory: $proxyFactory,
                    bootstrapServices: [
                        Config::class => $config,
                        Environment::class => $environment,
                        ContainerValue::class => new ContainerValue($container, $config),
                        AttributeDefinitionRegistry::class => $attributes,
                        AttributePlanBuilder::class => $plans,
                        AttributeProcessor::class => $attributeProcessor,
                        ParametersResolver::class => $parameters,
                        ObjectPipeline::class => $objects,
                    ],
                );
            },
        );

        $executor = new CallableExecutor(new CallableResolver($container), $parameters);
        $entryResolver = $this->createEntryResolver(
            $container,
            $proxyFactory,
            $objects,
            $executor,
        );
        $bootstrap->initialize($entryResolver, $executor);

        $handlers = $this->sharedAttributeHandlers($container, $proxyFactory);

        if (!$this->replaceAttributeDefinitions) {
            $this->registerBuiltInAttributes(
                $attributes,
                $container,
                $handlers,
                $resolutionParameters,
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
            $attributes->register($this->materializeAttributeDefinition($spec, $container));
        }

        foreach ($this->parameterResolvers as [$spec, $priority]) {
            $parameters->add($this->materializeResolver($spec, $container), $priority);
        }

        $parameters->seal();
        $attributes->seal();
        $bootstrapGuard->finish($parameters->resolverList);

        $container->get(Config::class);

        return $container;
    }

    private function createEntryResolver(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ObjectPipeline $objects,
        CallableExecutorInterface $executor,
    ): EntryResolverInterface {
        return new CompositeResolver(
            new EntryFactoryResolver(
                $this->factories,
                $container,
                $proxyFactory,
                $objects,
                $executor,
            ),
            new InvokableResolver($this->invokables),
            new ReflectionResolver($objects, [\Componenta\Config\DependencyDefinitions::class]),
        );
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

    /** @param array<class-string,AttributeHandlerInterface|ParameterAttributeHandlerInterface> $handlers */
    private function registerBuiltInAttributes(
        AttributeDefinitionRegistry $registry,
        Container $container,
        array $handlers,
        ObjectResolutionParameterStore $resolutionParameters,
    ): void {
        /** @var CastHandler $cast */
        $cast = $handlers[CastHandler::class];
        /** @var ConfigHandler $config */
        $config = $handlers[ConfigHandler::class];
        /** @var EntryIdHandler $entryId */
        $entryId = $handlers[EntryIdHandler::class];
        /** @var EnvHandler $env */
        $env = $handlers[EnvHandler::class];
        /** @var MakeHandler $make */
        $make = $handlers[MakeHandler::class];
        /** @var RequestAttributeHandler $request */
        $request = $handlers[RequestAttributeHandler::class];

        foreach ([
            [ConfigAttribute::class, $config, [ValueProvider::class]],
            [Env::class, $env, [ValueProvider::class]],
            [EntryId::class, $entryId, [ValueProvider::class]],
            [CurrentRequest::class, $request, [
                AuthoritativeValueProvider::class,
                InvocationOnlyValueProvider::class,
            ]],
            [CurrentUri::class, $request, [
                AuthoritativeValueProvider::class,
                InvocationOnlyValueProvider::class,
            ]],
            [Make::class, $make, [ValueProvider::class]],
            [Inject::class, new InjectHandler($container), [ValueProvider::class]],
            [Init::class, new InitHandler($container), [ValueProvider::class]],
        ] as [$attribute, $handler, $capabilities]) {
            $registry->register(new AttributeDefinition(
                $attribute,
                $handler,
                $capabilities,
            ));
        }

        foreach ([
            Header::class,
            Cookie::class,
            QueryParam::class,
            PayloadParam::class,
            RequestAttribute::class,
            ServerParam::class,
            UploadedFile::class,
            MapRequest::class,
            MapQueryString::class,
            MapRequestPayload::class,
            MapHeaders::class,
            MapCookies::class,
            MapRequestAttributes::class,
            MapServerParams::class,
            MapUploadedFiles::class,
        ] as $attribute) {
            $registry->register(new AttributeDefinition(
                $attribute,
                handler: $request,
                capabilities: [ValueProvider::class],
            ));
        }

        $registry->register(new AttributeDefinition(
            Cast::class,
            $cast,
            [ValueTransformer::class],
            after: [ValueProvider::class],
        ));
        $registry->register(new AttributeDefinition(
            Lazy::class,
            new LazyHandler(),
            [CreationStrategy::class],
            phase: AttributePhase::BeforeInstantiation,
        ));
        $registry->register(new AttributeDefinition(
            Proxy::class,
            $make,
            [CreationStrategy::class],
            before: [ValueTransformer::class],
            rules: [new ProxyCompositionRule()],
            phase: AttributePhase::Both,
        ));
        $registry->register(new AttributeDefinition(
            NoConstructor::class,
            new NoConstructorHandler(),
            [ConstructorPolicy::class],
            phase: AttributePhase::BeforeInstantiation,
        ));
        $registry->register(new AttributeDefinition(
            SetUp::class,
            new SetUpRunner(
                $container,
                $resolutionParameters,
                new ContainerValueUnwrapper(new ContainerValue($container, $this->config)),
                new EntryIdUnwrapper($container),
                new ConfigUnwrapper($container),
                new EnvUnwrapper($container),
            ),
            [LifecycleHook::class],
            phase: AttributePhase::AfterInstantiation,
        ));
    }

    /** @return array<class-string,AttributeHandlerInterface|ParameterAttributeHandlerInterface> */
    private function sharedAttributeHandlers(
        Container $container,
        ProxyFactoryInterface $proxyFactory,
    ): array {
        return [
            CastHandler::class => new CastHandler($container),
            ConfigHandler::class => new ConfigHandler($container),
            EntryIdHandler::class => new EntryIdHandler($container),
            EnvHandler::class => new EnvHandler($container),
            MakeHandler::class => new MakeHandler($container, $proxyFactory),
            RequestAttributeHandler::class => new RequestAttributeHandler(
                new LazyFactory($container),
                new LazyCasterProvider($container),
                new LazyValidationProvider($container),
            ),
        ];
    }

    private function materializeResolver(
        mixed $spec,
        ContainerInterface $container,
    ): ParameterResolverInterface {
        $resolver = $this->materializeExtension($spec, $container);
        if (!$resolver instanceof ParameterResolverInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Expected %s, got %s.',
                ParameterResolverInterface::class,
                get_debug_type($resolver),
            ));
        }
        return $resolver;
    }

    private function materializeAttributeDefinition(
        mixed $spec,
        ContainerInterface $container,
    ): AttributeDefinition {
        if ($spec instanceof AttributeDefinition) {
            return $spec;
        }
        $value = $this->materializeExtension($spec, $container);
        if (!$value instanceof AttributeDefinition) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute definition factory returned %s.',
                get_debug_type($value),
            ));
        }
        return $value;
    }

    private function materializeExtension(mixed $spec, ContainerInterface $container): object
    {
        if ($spec instanceof ParameterResolverInterface || $spec instanceof AttributeDefinition) {
            return $spec;
        }

        if ($spec instanceof Closure) {
            $extension = $spec($container);
        } elseif (is_string($spec)) {
            $extension = $container->has($spec)
                ? $container->get($spec)
                : (is_callable($spec) ? $spec($container) : $container->get($spec));
        } elseif (is_array($spec)
            && !is_callable($spec)
            && array_keys($spec) === [0, 1]
            && is_string($spec[0])
            && $spec[0] !== ''
            && is_string($spec[1])
            && $spec[1] !== ''
            && $container->has($spec[0])
        ) {
            $factory = [$container->get($spec[0]), $spec[1]];
            if (!is_callable($factory)) {
                throw new InvalidConfigurationException(sprintf(
                    'Extension service method "%s::%s" is not callable.',
                    $spec[0],
                    $spec[1],
                ));
            }
            $extension = $factory($container);
        } elseif (is_callable($spec)) {
            $extension = $spec($container);
        } else {
            throw new InvalidConfigurationException(sprintf(
                'Unsupported extension specification %s.',
                get_debug_type($spec),
            ));
        }

        if (!is_object($extension)) {
            throw new InvalidConfigurationException(sprintf(
                'Extension factory returned %s instead of an object.',
                get_debug_type($extension),
            ));
        }

        return $extension;
    }

    private function assertBindings(): void
    {
        $aliases = new AliasResolver($this->aliases);
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
