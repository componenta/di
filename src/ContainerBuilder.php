<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentUser;
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
use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerBootstrapState;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\ProtectedServiceIds;
use Componenta\DI\Internal\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Attribute\Handler\ConfigHandler;
use Componenta\DI\Resolver\Attribute\Handler\CurrentUserHandler;
use Componenta\DI\Resolver\Attribute\Handler\EntryIdHandler;
use Componenta\DI\Resolver\Attribute\Handler\EnvHandler;
use Componenta\DI\Resolver\Attribute\Handler\InitHandler;
use Componenta\DI\Resolver\Attribute\Handler\InjectHandler;
use Componenta\DI\Resolver\Attribute\Handler\LazyHandler;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
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
use Componenta\DI\Resolver\Parameter\Request\LazyCasterProvider;
use Componenta\DI\Resolver\Parameter\Request\LazyFactory;
use Componenta\DI\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\DI\Resolver\Parameter\RequestContextResolver;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Throwable;

/**
 * v5 composition root.
 *
 * Parameter values are produced only by ParameterResolverInterface. Parameter
 * attributes enter that pipeline through one AttributeParameterResolver; class,
 * property and method attributes execute through AttributeHandlerInterface.
 *
 * @phpstan-consistent-constructor
 */
class ContainerBuilder
{
    public const int PRIORITY_PARAM_ATTRIBUTE = 1200;
    public const int PRIORITY_PARAM_ARRAY = 1100;
    public const int PRIORITY_PARAM_ARRAY_TYPED = 1000;
    public const int PRIORITY_PARAM_REQUEST_CONTEXT = 800;
    public const int PRIORITY_PARAM_AUTOWIRE = 300;
    public const int PRIORITY_PARAM_DEFAULT_VALUE = 200;
    public const int PRIORITY_PARAM_NULLABLE = 100;

    public const int CACHE_VERSION = 16;

    /** @var array<string,non-empty-string> */
    private const array DEFAULT_ALIASES = [
        \Componenta\DI\Cache\DiCacheGeneratorInterface::class
            => \Componenta\DI\Cache\DiCacheGenerator::class,
    ];

    /** @var array<string,mixed> */
    public private(set) array $factories = [];
    /** @var list<class-string> */
    public private(set) array $invokables = [];
    /** @var array<string,non-empty-string> */
    public private(set) array $aliases = self::DEFAULT_ALIASES;
    /** @var array<string,list<callable|string|array{object|string,string}>> */
    public private(set) array $delegators = [];
    /** @var array<string,mixed> */
    public private(set) array $services = [];
    /** @var list<array{0:mixed,1:int}> */
    public private(set) array $parameterResolvers = [];
    /** @var list<mixed> */
    public private(set) array $attributeDefinitions = [];
    /** @var list<CapabilityPolicy> */
    public private(set) array $attributeCapabilities = [];
    public private(set) bool $replaceParameterResolvers = false;
    public private(set) bool $replaceAttributeDefinitions = false;
    public private(set) ?Config $config = null;

    private ?string $compiledFactoryBaseDir = null;

    public function __construct() {}

    public static function configure(Config $config): static
    {
        $dependencies = $config->has(ConfigKey::DEPENDENCIES)
            ? $config->get(ConfigKey::DEPENDENCIES)
            : [];
        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException('Container dependencies section must be an array.');
        }

        /** @var array<array-key,mixed> $dependencies */
        return static::configureWithDependencies($config, $dependencies);
    }

    /** @param array<array-key,mixed> $dependencies */
    public static function configureWithDependencies(Config $config, array $dependencies): static
    {
        $dependencies = DependencyConfiguration::normalize($dependencies, self::DEFAULT_ALIASES);
        $builder = new static();

        $builder->factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        $builder->invokables = $dependencies[ConfigKey::INVOKABLES] ?? [];
        $builder->aliases = $dependencies[ConfigKey::ALIASES] ?? self::DEFAULT_ALIASES;
        $builder->delegators = $dependencies[ConfigKey::DELEGATORS] ?? [];
        $builder->services = $dependencies[ConfigKey::SERVICES] ?? [];

        foreach ($dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [] as $priority => $resolver) {
            $replaced = false;
            foreach ($builder->parameterResolvers as $index => [, $registeredPriority]) {
                if ($registeredPriority !== $priority) {
                    continue;
                }

                $builder->parameterResolvers[$index] = [$resolver, $priority];
                $replaced = true;
                break;
            }

            if (!$replaced) {
                $builder->parameterResolvers[] = [$resolver, $priority];
            }
        }

        $builder->replaceParameterResolvers = $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false;
        $builder->attributeDefinitions = array_values($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS] ?? []);
        $builder->replaceAttributeDefinitions = $dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE] ?? false;
        $builder->attributeCapabilities = array_values($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? []);
        $builder->config = self::configWithDependencies($config, $dependencies);

        return $builder;
    }

    /** @param array<string,mixed> $cache */
    public static function configureFromCache(Config $config, array $cache, ?string $baseDir = null): static
    {
        $builder = static::configureWithDependencies(
            $config,
            DependencyConfiguration::dependenciesFromCache($cache, self::CACHE_VERSION),
        );
        $builder->compiledFactoryBaseDir = $baseDir;
        return $builder;
    }

    /**
     * @param array<array-key,mixed> $dependencies
     * @return array<string,mixed>
     */
    public static function normalizeDependencies(array $dependencies): array
    {
        $normalized = DependencyConfiguration::normalize($dependencies, self::DEFAULT_ALIASES);
        $validator = static::configureWithDependencies(
            new Config([], new Environment([])),
            $normalized,
        );
        $validator->assertBindings();
        return $normalized;
    }

    public function build(): Container
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

        $config = $this->config ?? new Config([], new Environment([]));
        $environment = $config->environment ?? new Environment([]);
        $attributes = new AttributeDefinitionRegistry();
        $plans = new AttributePlanBuilder($attributes);
        $parameters = new ParametersResolver($plans);
        $attributeProcessor = new AttributeProcessor($attributes, $plans);
        $proxyFactory = $this->createProxyFactory();
        $objects = new ObjectPipeline(
            $plans,
            new InstanceCreator($parameters),
            $proxyFactory,
            $attributes,
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
        if (!$this->hasBinding(CurrentUserProviderInterface::class)
            && !$cache->tryGetBase(CurrentUserProviderInterface::class, $registeredCurrentUserProvider)
        ) {
            $cache->putBase(CurrentUserProviderInterface::class, new CurrentUserProvider());
        }

        $bootstrap = new ContainerBootstrapState();
        /** @var ReflectionClass<Container> $containerClass */
        $containerClass = new ReflectionClass(Container::class);
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
            $attributes,
            $parameters,
        );
        $bootstrap->initialize($entryResolver, $executor);

        $handlers = $this->sharedAttributeHandlers($container, $proxyFactory);

        if (!$this->replaceAttributeDefinitions) {
            $this->registerBuiltInAttributes($attributes, $container, $handlers);
        }

        foreach ($this->attributeCapabilities as $policy) {
            $attributes->defineCapability($policy);
        }

        foreach ($this->attributeDefinitions as $spec) {
            $attributes->register($this->materializeAttributeDefinition($spec, $container));
        }

        if (!$this->replaceParameterResolvers) {
            foreach ($this->defaultParameterResolvers($container, $plans) as [$resolver, $priority]) {
                $parameters->add($resolver, $priority);
            }
        }

        foreach ($this->parameterResolvers as [$spec, $priority]) {
            $parameters->add($this->materializeResolver($spec, $container), $priority);
        }

        $parameters->seal();
        $attributes->seal();

        $container->get(Config::class);

        foreach ($this->delegators as $id => $items) {
            foreach ($items as $delegator) {
                $container->delegator($id, $delegator);
            }
        }

        return $container;
    }

    /**
     * @param iterable<AutowireEntry|class-string> $entries
     * @return array<class-string,CompiledFactoryDefinition>
     */
    public function compileFactories(
        iterable $entries,
        string $directory,
        int $maxShardBytes = CompiledFactoryShardCompiler::DEFAULT_MAX_BYTES,
        string $namespace = 'Componenta\\DI\\Generated',
    ): array {
        try {
            return $this->compileFactoryArtifacts($entries, $directory, $maxShardBytes, $namespace);
        } catch (InvalidConfigurationException|CompilationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw CompilationException::forArtifact($directory, $e);
        }
    }

    /**
     * @param iterable<AutowireEntry|class-string> $entries
     * @return array<class-string,CompiledFactoryDefinition>
     */
    private function compileFactoryArtifacts(
        iterable $entries,
        string $directory,
        int $maxShardBytes,
        string $namespace,
    ): array {
        $container = $this->build();
        $objects = $container->get(ObjectPipeline::class);
        $attributes = $container->get(AttributeDefinitionRegistry::class);
        $parameters = $container->get(ParametersResolver::class);
        if (!$objects instanceof ObjectPipeline
            || !$attributes instanceof AttributeDefinitionRegistry
            || !$parameters instanceof ParametersResolver
        ) {
            throw new InvalidConfigurationException('Runtime compiler services are unavailable.');
        }

        $aliasResolver = new AliasResolver($this->aliases);
        $excluded = array_fill_keys(ProtectedServiceIds::ids(), true);
        foreach ([
            ...array_keys($this->factories),
            ...array_keys($this->services),
            ...$this->invokables,
        ] as $id) {
            $excluded[$aliasResolver->resolve($id)] = true;
        }

        $classes = (new AutowireClassGraph($this->aliases))->expand($entries, $excluded);
        if ($classes === []) {
            return [];
        }

        return (new CompiledFactoryShardCompiler(
            new FactoryCodeGenerator(),
            compiled_factory_pipeline_fingerprint($attributes, $parameters),
            objects: $objects,
        ))->compile($classes, $directory, $maxShardBytes, $namespace);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        /** @var array<string,mixed> $data */
        $data = $this->config?->toArray() ?? [];
        $data[ConfigKey::DEPENDENCIES] = [
            ConfigKey::FACTORIES => $this->factories,
            ConfigKey::INVOKABLES => $this->invokables,
            ConfigKey::ALIASES => $this->aliases,
            ConfigKey::DELEGATORS => $this->delegators,
            ConfigKey::SERVICES => $this->services,
            ConfigKey::PARAMETER_RESOLVERS => $this->resolversToMap($this->parameterResolvers),
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => $this->replaceParameterResolvers,
            ConfigKey::ATTRIBUTE_DEFINITIONS => $this->attributeDefinitions,
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => $this->replaceAttributeDefinitions,
            ConfigKey::ATTRIBUTE_CAPABILITIES => $this->attributeCapabilities,
        ];
        return $data;
    }

    protected function createEntryResolver(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ObjectPipeline $objects,
        CallableExecutorInterface $executor,
        AttributeDefinitionRegistry $attributes,
        ParametersResolver $parameters,
    ): EntryResolverInterface {
        return new CompositeResolver(
            new EntryFactoryResolver(
                $this->factories,
                $container,
                $proxyFactory,
                $objects,
                $executor,
                $attributes,
                $parameters,
                $this->compiledFactoryBaseDir,
            ),
            new InvokableResolver($this->invokables),
            new ReflectionResolver($objects),
        );
    }

    /** @return list<array{0:ParameterResolverInterface,1:int}> */
    protected function defaultParameterResolvers(
        ContainerInterface $container,
        AttributePlanBuilder $plans,
    ): array {
        return [
            [new AttributeParameterResolver($plans), self::PRIORITY_PARAM_ATTRIBUTE],
            [new ParameterArrayResolver(), self::PRIORITY_PARAM_ARRAY],
            [new ArrayTypedResolver(), self::PRIORITY_PARAM_ARRAY_TYPED],
            [new RequestContextResolver(), self::PRIORITY_PARAM_REQUEST_CONTEXT],
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
    ): void {
        foreach ([
            new CapabilityPolicy(ValueProvider::class, 1),
            new CapabilityPolicy(ValueTransformer::class),
            new CapabilityPolicy(CreationStrategy::class, 1),
            new CapabilityPolicy(ConstructorPolicy::class, 1),
            new CapabilityPolicy(LifecycleHook::class),
        ] as $policy) {
            $registry->defineCapability($policy);
        }

        /** @var CastHandler $cast */
        $cast = $handlers[CastHandler::class];
        /** @var ConfigHandler $config */
        $config = $handlers[ConfigHandler::class];
        /** @var CurrentUserHandler $currentUser */
        $currentUser = $handlers[CurrentUserHandler::class];
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
            [CurrentUser::class, $currentUser, [AuthoritativeValueProvider::class]],
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
            CurrentUserHandler::class => new CurrentUserHandler($container),
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

    protected function materializeResolver(
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

    protected function createProxyFactory(): ProxyFactoryInterface
    {
        return new ProxyFactory();
    }

    /** @param callable(ContainerValue,array<string|int,mixed>):mixed $factory */
    public function addFactory(string $id, callable $factory): static
    {
        self::assertId($id, 'factory');
        FactorySpecificationValidator::assertValid($id, $factory);
        $this->factories[$id] = $factory;
        return $this;
    }

    public function addDefinition(string $id, DefinitionInterface $definition): static
    {
        self::assertId($id, 'definition');
        FactorySpecificationValidator::assertValid($id, $definition);
        $this->factories[$id] = $definition;
        return $this;
    }

    /** @param array<string,mixed> $factories */
    public function addFactories(array $factories): static
    {
        foreach ($factories as $id => $factory) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException('Factory ids must be strings.');
            }
            if ($factory instanceof DefinitionInterface) {
                $this->addDefinition($id, $factory);
            } elseif (is_callable($factory)) {
                $this->addFactory($id, $factory);
            } else {
                throw new InvalidConfigurationException(sprintf(
                    'Factory "%s" must be callable or DefinitionInterface.',
                    $id,
                ));
            }
        }
        return $this;
    }

    public function addInvokable(string $classOrAlias, ?string $class = null): static
    {
        $target = $class ?? $classOrAlias;
        self::assertId($target, 'invokable');
        if (!class_exists($target)) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" does not exist.',
                $target,
            ));
        }
        if (!in_array($target, $this->invokables, true)) {
            $this->invokables[] = $target;
        }
        if ($class !== null) {
            $this->addAlias($classOrAlias, $class);
        }
        return $this;
    }

    /** @param array<int|string,class-string> $invokables */
    public function addInvokables(array $invokables): static
    {
        foreach ($invokables as $key => $class) {
            is_int($key) ? $this->addInvokable($class) : $this->addInvokable($key, $class);
        }
        return $this;
    }

    public function addAlias(string $alias, string $target): static
    {
        self::assertId($alias, 'alias');
        if ($target === '') {
            throw new InvalidConfigurationException('Alias target must be non-empty.');
        }
        $this->aliases[$alias] = $target;
        return $this;
    }

    /** @param array<string,string> $aliases */
    public function addAliases(array $aliases): static
    {
        foreach ($aliases as $alias => $target) {
            $this->addAlias($alias, $target);
        }
        return $this;
    }

    public function addDelegator(string $id, mixed $delegator): static
    {
        self::assertId($id, 'delegator');
        $this->delegators[$id][] = DependencyConfiguration::normalizeDelegatorSpecification($delegator, $id);
        return $this;
    }

    /** @param array<string,mixed> $delegators */
    public function addDelegators(array $delegators): static
    {
        foreach ($delegators as $id => $items) {
            foreach (DependencyConfiguration::normalizeDelegatorList($items, $id) as $item) {
                $this->addDelegator($id, $item);
            }
        }
        return $this;
    }

    public function addService(string $id, mixed $service): static
    {
        self::assertId($id, 'service');
        $this->services[$id] = $service;
        return $this;
    }

    /** @param array<string,mixed> $services */
    public function addServices(array $services): static
    {
        foreach ($services as $id => $service) {
            $this->addService($id, $service);
        }
        return $this;
    }

    public function addParameterResolver(mixed $resolver, int $priority = 0): static
    {
        if (!$resolver instanceof ParameterResolverInterface) {
            DependencyConfiguration::assertExtensionSpecification($resolver, 'parameter resolver');
        }
        foreach ($this->parameterResolvers as [, $registeredPriority]) {
            if ($registeredPriority === $priority) {
                throw new InvalidConfigurationException(sprintf(
                    'Parameter resolver priority %d is already registered.',
                    $priority,
                ));
            }
        }
        $this->parameterResolvers[] = [$resolver, $priority];
        return $this;
    }

    public function replaceParameterResolvers(bool $replace = true): static
    {
        $this->replaceParameterResolvers = $replace;
        return $this;
    }

    public function addAttributeDefinition(mixed $definition): static
    {
        if (!$definition instanceof AttributeDefinition) {
            DependencyConfiguration::assertExtensionSpecification($definition, 'attribute definition');
        }
        $this->attributeDefinitions[] = $definition;
        return $this;
    }

    public function replaceAttributeDefinitions(bool $replace = true): static
    {
        $this->replaceAttributeDefinitions = $replace;
        return $this;
    }

    public function defineAttributeCapability(CapabilityPolicy $policy): static
    {
        $this->attributeCapabilities[] = $policy;
        return $this;
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

    private function hasBinding(string $id): bool
    {
        return array_key_exists($id, $this->services)
            || array_key_exists($id, $this->factories)
            || array_key_exists($id, $this->aliases)
            || in_array($id, $this->invokables, true);
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

    /**
     * @param list<array{0:mixed,1:int}> $resolvers
     * @return array<int,mixed>
     */
    private function resolversToMap(array $resolvers): array
    {
        $map = [];
        foreach ($resolvers as [$resolver, $priority]) {
            if (array_key_exists($priority, $map)) {
                throw new InvalidConfigurationException(sprintf(
                    'Parameter resolver priority %d is registered more than once.',
                    $priority,
                ));
            }
            $map[$priority] = $resolver;
        }
        return $map;
    }

    /** @param array<string,mixed> $dependencies */
    private static function configWithDependencies(Config $config, array $dependencies): Config
    {
        /** @var array<string,mixed> $data */
        $data = $config->toArray();
        $data[ConfigKey::DEPENDENCIES] = $dependencies;
        return new Config($data, $config->environment);
    }
}
