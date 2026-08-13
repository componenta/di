<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Compile\Autowire\AutowireEntry;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
use Componenta\DI\Compile\Parameter\DefaultParameterResolverCodeGenerators;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\InitHandler;
use Componenta\DI\Resolver\Attribute\Handler\InjectHandler;
use Componenta\DI\Resolver\Attribute\Handler\LazyHandler;
use Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler;
use Componenta\DI\Resolver\Attribute\Handler\ProxyHandler;
use Componenta\DI\Resolver\ConfigAttributeResolver;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver as EntryFactoryResolver;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Entry\InvokableSpecificationValidator;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Entry\SetUp\ConfigUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\ContainerValueUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EntryIdUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EnvUnwrapper;
use Componenta\DI\Resolver\Entry\SetUpRunner;
use Componenta\DI\Resolver\EntryIdResolver;
use Componenta\DI\Resolver\EnvResolver;
use Componenta\DI\Resolver\MakeAttributeResolver;
use Componenta\DI\Resolver\Parameter\ArrayResolver as ParameterArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * Builds the runtime container and its resolver/attribute pipelines.
 *
 * Declarative dependency validation/normalization is delegated to
 * DependencyConfiguration; AOT class discovery/expansion is delegated to
 * AutowireClassGraph. Runtime composition remains the responsibility of this
 * builder.
 *
 * @phpstan-type CallableReference array{0: object|non-empty-string, 1: non-empty-string}
 * @phpstan-type DelegatorSpecification callable|non-empty-string|CallableReference
 */
class ContainerBuilder
{
    public const int PRIORITY_PARAM_CASTABLE = 1200;
    public const int PRIORITY_PARAM_ARRAY = 1100;
    public const int PRIORITY_PARAM_ARRAY_TYPED = 1000;
    public const int PRIORITY_PARAM_CURRENT_USER = 900;
    public const int PRIORITY_PARAM_REQUEST = 800;
    public const int PRIORITY_PARAM_MAKE = 700;
    public const int PRIORITY_PARAM_ENV = 600;
    public const int PRIORITY_PARAM_ENTRY_ID = 500;
    public const int PRIORITY_PARAM_CONFIG = 400;
    public const int PRIORITY_PARAM_AUTOWIRE = 300;
    public const int PRIORITY_PARAM_DEFAULT_VALUE = 200;
    public const int PRIORITY_PARAM_NULLABLE = 100;

    public const int CACHE_VERSION = 8;

    /** @var array<string, non-empty-string> */
    private const array DEFAULT_ALIASES = [
        \Componenta\DI\Cache\DiCacheGeneratorInterface::class
            => \Componenta\DI\Cache\DiCacheGenerator::class,
    ];

    /** @var array<string, mixed> */
    public private(set) array $factories = [];

    /** @var list<class-string> */
    public private(set) array $invokables = [];

    /** @var array<string, non-empty-string> */
    public private(set) array $aliases = self::DEFAULT_ALIASES;

    /** @var array<string, list<DelegatorSpecification>> */
    public private(set) array $delegators = [];

    /** @var array<string, mixed> */
    public private(set) array $services = [];

    /** @var list<array{0: mixed, 1: int}> */
    public private(set) array $parameterResolvers = [];

    /** @var list<mixed> */
    public private(set) array $attributeHandlers = [];

    public private(set) bool $replaceParameterResolvers = false;

    public private(set) bool $replaceAttributeHandlers = false;

    public private(set) ?Config $config = null;

    private ?string $compiledFactoryBaseDir = null;

    /** Current builder state has passed complete binding validation. */
    private bool $bindingsValidated = false;

    /** @var array<class-string, ParameterResolverInterface&AttributeHandlerInterface>|null */
    private ?array $sharedResolvers = null;

    public function __construct() {}

    public static function configure(Config $config): static
    {
        $dependencies = $config->has(ConfigKey::DEPENDENCIES)
            ? $config->get(ConfigKey::DEPENDENCIES)
            : [];

        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException(
                'Container dependencies section must be an array.',
            );
        }

        /** @var array<string, mixed> $dependencies */
        return static::configureWithDependencies($config, $dependencies);
    }

    /** @param array<string, mixed> $dependencies */
    public static function configureWithDependencies(
        Config $config,
        array $dependencies,
    ): static {
        DependencyConfiguration::assertShape($dependencies);

        $builder = self::newBuilder();
        $builder->factories = array_merge(
            $builder->factories,
            $dependencies[ConfigKey::FACTORIES] ?? [],
        );
        $builder->aliases = array_merge(
            $builder->aliases,
            $dependencies[ConfigKey::ALIASES] ?? [],
        );
        $builder->services = array_merge(
            $builder->services,
            $dependencies[ConfigKey::SERVICES] ?? [],
        );

        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $delegatorList) {
            $builder->delegators[$id] = [
                ...($builder->delegators[$id] ?? []),
                ...DependencyConfiguration::normalizeDelegatorList(
                    $delegatorList,
                    $id,
                ),
            ];
        }

        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $key => $value) {
            if (!in_array($value, $builder->invokables, true)) {
                $builder->invokables[] = $value;
            }

            if (is_string($key)) {
                DependencyConfiguration::assertInvokableAliasCompatible(
                    $builder->aliases,
                    $key,
                    $value,
                );
                $builder->aliases[$key] ??= $value;
            }
        }

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

        foreach ($dependencies[ConfigKey::ATTRIBUTE_HANDLERS] ?? [] as $handler) {
            $builder->attributeHandlers[] = $handler;
        }

        $builder->replaceParameterResolvers = $dependencies[
            ConfigKey::PARAMETER_RESOLVERS_REPLACE
        ] ?? $builder->replaceParameterResolvers;
        $builder->replaceAttributeHandlers = $dependencies[
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE
        ] ?? $builder->replaceAttributeHandlers;
        $builder->config = self::configWithDependencies($config, $dependencies);

        return $builder;
    }

    /** @param array<string, mixed> $cache */
    public static function configureFromCache(
        Config $config,
        array $cache,
        ?string $baseDir = null,
    ): static {
        $dependencies = DependencyConfiguration::dependenciesFromCache(
            $cache,
            self::CACHE_VERSION,
        );
        $builder = static::configureWithDependencies($config, $dependencies);
        $builder->compiledFactoryBaseDir = $baseDir;

        $builder->assertNoReservedBindings();
        $builder->bindingsValidated = true;

        return $builder;
    }

    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    public static function normalizeDependencies(array $dependencies): array
    {
        $normalized = DependencyConfiguration::normalize(
            $dependencies,
            self::DEFAULT_ALIASES,
        );

        $validator = static::configureWithDependencies(new Config([]), $normalized);
        $validator->assertNoReservedBindings();

        return $normalized;
    }

    public function build(): Container
    {
        if (!$this->bindingsValidated) {
            $this->assertNoReservedBindings();
            $this->bindingsValidated = true;
        }

        $this->sharedResolvers = null;
        $config = $this->config;

        if ($config === null) {
            $environment = new Environment([]);
            $config = new Config([], $environment);
        } else {
            $environment = $config->environment ?? new Environment([]);
        }

        $parametersResolver = new ParametersResolver();
        $handlerRegistry = new AttributeHandlerRegistry();
        $attributeProcessor = new AttributeProcessor($handlerRegistry);
        $aliases = new AliasResolver([
            ...$this->aliases,
            'config' => Config::class,
        ]);
        $proxyFactory = $this->createProxyFactory();
        $bootstrap = new ContainerBootstrapState();

        /** @var ReflectionClass<Container> $containerClass */
        $containerClass = new ReflectionClass(Container::class);
        $container = $containerClass->newLazyGhost(
            static function (Container $container) use (
                $bootstrap,
                $aliases,
                $proxyFactory,
                $config,
                $environment,
                $parametersResolver,
                $handlerRegistry,
                $attributeProcessor,
            ): void {
                $container->__construct(
                    resolver: $bootstrap->entryResolver(),
                    aliases: $aliases,
                    callableExecutor: $bootstrap->callableExecutor(),
                    proxyFactory: $proxyFactory,
                    bootstrapServices: [
                        Config::class => $config,
                        Environment::class => $environment,
                        ContainerValue::class => new ContainerValue($container, $config),
                        ParametersResolver::class => $parametersResolver,
                        AttributeHandlerRegistry::class => $handlerRegistry,
                        AttributeProcessor::class => $attributeProcessor,
                    ],
                );
            },
        );

        $callableResolver = new CallableResolver($container);
        $callableExecutor = new CallableExecutor($callableResolver, $parametersResolver);
        $entryResolver = $this->createEntryResolver(
            $parametersResolver,
            $attributeProcessor,
            $container,
            $proxyFactory,
        );
        $bootstrap->initialize($entryResolver, $callableExecutor);

        $container->get(Config::class);

        foreach ($this->services as $id => $service) {
            $container->set($id, $service);
        }

        foreach ($this->delegators as $id => $delegatorList) {
            foreach ($delegatorList as $delegator) {
                $container->delegator($id, $delegator);
            }
        }

        $this->fillPipelines(
            $parametersResolver,
            $handlerRegistry,
            $container,
        );

        foreach ($this->invokables as $class) {
            InvokableSpecificationValidator::assertValid($class, $attributeProcessor);
        }

        $parametersResolver->seal();
        $handlerRegistry->seal();

        return $container;
    }

    /**
     * Compiles known autowiring roots and their concrete dependency graph into
     * lazy, content-addressed factory shards.
     *
     * @param iterable<AutowireEntry|class-string> $entries
     * @return array<class-string, CompiledFactoryDefinition>
     */
    public function compileFactories(
        iterable $entries,
        string $directory,
        ?ParameterResolverCodeGeneratorRegistry $generators = null,
        int $maxShardBytes = CompiledFactoryShardCompiler::DEFAULT_MAX_BYTES,
        string $namespace = 'Componenta\\DI\\Generated',
    ): array {
        $container = $this->build();
        $parameters = $container->get(ParametersResolver::class);
        $attributes = $container->get(AttributeProcessor::class);

        if (!$parameters instanceof ParametersResolver || !$attributes instanceof AttributeProcessor) {
            throw new InvalidConfigurationException('Runtime DI compiler services are unavailable.');
        }

        $aliases = new AliasResolver($this->aliases);
        $excluded = [];

        foreach (ProtectedServiceIds::ids() as $id) {
            $excluded[$id] = true;
        }

        foreach ([
            ...array_keys($this->factories),
            ...array_keys($this->services),
            ...$this->invokables,
        ] as $id) {
            $excluded[$aliases->resolve($id)] = true;
        }

        $classes = (new AutowireClassGraph($this->aliases))->expand(
            $entries,
            $excluded,
        );

        if ($classes === []) {
            return [];
        }

        $generators ??= DefaultParameterResolverCodeGenerators::create();
        $parameterCode = new ParameterCodeGenerator($parameters, $generators);
        $factoryCode = new FactoryCodeGenerator($parameterCode, $attributes);

        return (new CompiledFactoryShardCompiler($factoryCode))->compile(
            $classes,
            $directory,
            $maxShardBytes,
            $namespace,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->config?->toArray() ?? [];
        $data[ConfigKey::DEPENDENCIES] = [
            ConfigKey::FACTORIES => $this->factories,
            ConfigKey::INVOKABLES => $this->invokables,
            ConfigKey::ALIASES => $this->aliases,
            ConfigKey::DELEGATORS => $this->delegators,
            ConfigKey::SERVICES => $this->services,
            ConfigKey::PARAMETER_RESOLVERS
                => $this->resolversToMap($this->parameterResolvers),
            ConfigKey::PARAMETER_RESOLVERS_REPLACE
                => $this->replaceParameterResolvers,
            ConfigKey::ATTRIBUTE_HANDLERS => $this->attributeHandlers,
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE
                => $this->replaceAttributeHandlers,
        ];

        return $data;
    }

    protected function createEntryResolver(
        ParametersResolver $parametersResolver,
        AttributeProcessor $attributeProcessor,
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
    ): EntryResolverInterface {
        return new CompositeResolver(
            new EntryFactoryResolver(
                $this->factories,
                $container,
                $proxyFactory,
                $parametersResolver,
                $attributeProcessor,
                $this->compiledFactoryBaseDir,
            ),
            new InvokableResolver(
                $this->invokables,
                $proxyFactory,
                $attributeProcessor,
            ),
            new ReflectionResolver(
                new InstanceCreator($parametersResolver),
                $attributeProcessor,
                $proxyFactory,
            ),
        );
    }

    protected function fillPipelines(
        ParametersResolver $parameters,
        AttributeHandlerRegistry $handlers,
        ContainerInterface $container,
    ): void {
        if (!$this->replaceParameterResolvers) {
            foreach ($this->buildDefaultParameterResolvers($container) as [$resolver, $priority]) {
                $parameters->add($resolver, $priority);
            }
        }

        if (!$this->replaceAttributeHandlers) {
            $handlers->addAll($this->buildDefaultAttributeHandlers($container));
        }

        foreach ($this->parameterResolvers as [$config, $priority]) {
            $parameters->add($this->materializeResolver($config, $container), $priority);
        }

        foreach ($this->attributeHandlers as $config) {
            $handlers->add($this->materializeHandler($config, $container));
        }
    }

    /** @return array<string, array{0: ParameterResolverInterface, 1: int}> */
    protected function buildDefaultParameterResolvers(
        ContainerInterface $container,
    ): array {
        $shared = $this->sharedResolvers($container);

        return [
            ParameterArrayResolver::class => [
                new ParameterArrayResolver(),
                self::PRIORITY_PARAM_ARRAY,
            ],
            ArrayTypedResolver::class => [
                new ArrayTypedResolver(),
                self::PRIORITY_PARAM_ARRAY_TYPED,
            ],
            MakeAttributeResolver::class => [
                $shared[MakeAttributeResolver::class],
                self::PRIORITY_PARAM_MAKE,
            ],
            EnvResolver::class => [
                $shared[EnvResolver::class],
                self::PRIORITY_PARAM_ENV,
            ],
            EntryIdResolver::class => [
                $shared[EntryIdResolver::class],
                self::PRIORITY_PARAM_ENTRY_ID,
            ],
            ConfigAttributeResolver::class => [
                $shared[ConfigAttributeResolver::class],
                self::PRIORITY_PARAM_CONFIG,
            ],
            AutowireByTypeResolver::class => [
                new AutowireByTypeResolver($container),
                self::PRIORITY_PARAM_AUTOWIRE,
            ],
            DefaultValueResolver::class => [
                new DefaultValueResolver(),
                self::PRIORITY_PARAM_DEFAULT_VALUE,
            ],
            NullableResolver::class => [
                new NullableResolver(),
                self::PRIORITY_PARAM_NULLABLE,
            ],
        ];
    }

    /** @return list<AttributeHandlerInterface> */
    protected function buildDefaultAttributeHandlers(
        ContainerInterface $container,
    ): array {
        $shared = $this->sharedResolvers($container);
        $callableInvoker = $container instanceof CallableInvokerInterface
            ? $container
            : $container->get(CallableInvokerInterface::class);

        if (!$callableInvoker instanceof CallableInvokerInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Internal service "%s" must implement %s; got %s.',
                CallableInvokerInterface::class,
                CallableInvokerInterface::class,
                get_debug_type($callableInvoker),
            ));
        }

        return [
            new NoConstructorHandler(),
            new ProxyHandler(),
            new LazyHandler(),
            new InitHandler($callableInvoker),
            $shared[MakeAttributeResolver::class],
            $shared[EnvResolver::class],
            $shared[EntryIdResolver::class],
            new InjectHandler($container),
            $shared[ConfigAttributeResolver::class],
            new SetUpRunner(
                $callableInvoker,
                new ContainerValueUnwrapper($this->containerValue($container)),
                new EntryIdUnwrapper($container),
                new ConfigUnwrapper($container),
                new EnvUnwrapper($container),
            ),
        ];
    }

    protected function createProxyFactory(): ProxyFactoryInterface
    {
        return new ProxyFactory();
    }

    private function containerValue(ContainerInterface $container): ContainerValue
    {
        $value = $container->get(ContainerValue::class);

        if (!$value instanceof ContainerValue) {
            throw new InvalidConfigurationException(sprintf(
                'Internal service "%s" must be an instance of %s.',
                ContainerValue::class,
                ContainerValue::class,
            ));
        }

        return $value;
    }

    private function assertNoReservedBindings(): void
    {
        if ($this->factories === []
            && $this->invokables === []
            && $this->aliases === self::DEFAULT_ALIASES
            && $this->delegators === []
            && $this->services === []
        ) {
            return;
        }

        $aliases = new AliasResolver($this->aliases);

        foreach ([
            'factory' => array_keys($this->factories),
            'service' => array_keys($this->services),
            'delegator' => array_keys($this->delegators),
            'invokable' => $this->invokables,
        ] as $kind => $ids) {
            foreach ($ids as $id) {
                self::assertBindingIdAvailable($id, $kind);

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
                        'Cannot register %s for id "%s" because it resolves to reserved DI id "%s".',
                        $kind,
                        $id,
                        $canonical,
                    ));
                }
            }
        }

        foreach (array_keys($this->aliases) as $alias) {
            self::assertBindingIdAvailable($alias, 'alias');
        }

        $this->assertSingleBindingPerCanonicalId($aliases);
    }

    private function invalidateBindingValidationFor(string $id, string $kind): void
    {
        if (!$this->bindingsValidated) {
            return;
        }

        if (isset($this->aliases[$id]) || in_array($id, $this->aliases, true)) {
            $this->bindingsValidated = false;
            return;
        }

        $hasConflict = match ($kind) {
            'factory' => isset($this->services[$id]) || in_array($id, $this->invokables, true),
            'invokable' => isset($this->factories[$id]) || isset($this->services[$id]),
            'service' => isset($this->factories[$id]) || in_array($id, $this->invokables, true),
            default => false,
        };

        if ($hasConflict) {
            $this->bindingsValidated = false;
        }
    }

    private function assertSingleBindingPerCanonicalId(AliasResolverInterface $aliases): void
    {
        /** @var array<string, array{kind: string, id: string}> $owners */
        $owners = [];

        foreach ([
            'factory' => array_keys($this->factories),
            'invokable' => $this->invokables,
            'service' => array_keys($this->services),
        ] as $kind => $ids) {
            foreach ($ids as $id) {
                $canonical = $aliases->resolve($id);
                $owner = $owners[$canonical] ?? null;

                if ($owner !== null) {
                    throw new InvalidConfigurationException(sprintf(
                        'Canonical DI id "%s" has conflicting %s binding "%s" and %s binding "%s".',
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
    }

    private static function assertBindingIdAvailable(string $id, string $kind): void
    {
        if ($id === '') {
            throw new InvalidConfigurationException(sprintf(
                'Cannot register %s with an empty DI id.',
                $kind,
            ));
        }

        if (ProtectedServiceIds::contains($id)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot register %s for reserved DI id "%s".',
                $kind,
                $id,
            ));
        }
    }

    /** @return array<class-string, ParameterResolverInterface&AttributeHandlerInterface> */
    private function sharedResolvers(ContainerInterface $container): array
    {
        if ($this->sharedResolvers !== null) {
            return $this->sharedResolvers;
        }

        if (!$container instanceof FactoryInterface) {
            throw new InvalidConfigurationException('FactoryInterface service is unavailable.');
        }

        if (!$container instanceof ProxyFactoryInterface) {
            throw new InvalidConfigurationException(
                'ProxyFactoryInterface service is unavailable.',
            );
        }

        return $this->sharedResolvers = [
            MakeAttributeResolver::class => new MakeAttributeResolver(
                $container,
                $container,
            ),
            EnvResolver::class => new EnvResolver($container),
            EntryIdResolver::class => new EntryIdResolver($container),
            ConfigAttributeResolver::class => new ConfigAttributeResolver($container),
        ];
    }

    protected function materializeResolver(
        mixed $config,
        ContainerInterface $container,
    ): ParameterResolverInterface {
        $resolver = $this->materializeExtension($config, $container);

        if (!$resolver instanceof ParameterResolverInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Expected %s, got %s.',
                ParameterResolverInterface::class,
                get_debug_type($resolver),
            ));
        }

        return $resolver;
    }

    protected function materializeHandler(
        mixed $config,
        ContainerInterface $container,
    ): AttributeHandlerInterface {
        $handler = $this->materializeExtension($config, $container);

        if (!$handler instanceof AttributeHandlerInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Expected %s, got %s.',
                AttributeHandlerInterface::class,
                get_debug_type($handler),
            ));
        }

        return $handler;
    }

    private function materializeExtension(
        mixed $config,
        ContainerInterface $container,
    ): object {
        if ($config instanceof ParameterResolverInterface
            || $config instanceof AttributeHandlerInterface
        ) {
            return $config;
        }

        if ($config instanceof Closure) {
            $extension = $config($container);
        } elseif (is_string($config)) {
            $extension = $container->has($config)
                ? $container->get($config)
                : (is_callable($config) ? $config($container) : $container->get($config));
        } elseif (is_array($config)
            && !is_callable($config)
            && array_keys($config) === [0, 1]
            && is_string($config[0])
            && is_string($config[1])
            && $container->has($config[0])
        ) {
            $factory = [$container->get($config[0]), $config[1]];
            if (!is_callable($factory)) {
                throw new InvalidConfigurationException(sprintf(
                    'Extension service method "%s::%s" is not callable.',
                    $config[0],
                    $config[1],
                ));
            }
            $extension = $factory($container);
        } elseif (is_callable($config)) {
            $extension = $config($container);
        } else {
            throw new InvalidConfigurationException(sprintf(
                'Extension specification must be an instance, callable or service id; got %s.',
                get_debug_type($config),
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

    /**
     * @param list<array{0: mixed, 1: int}> $resolvers
     * @return array<int, mixed>
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

    /** @param array<string, mixed> $dependencies */
    private static function configWithDependencies(
        Config $config,
        array $dependencies,
    ): Config {
        /** @var array<string, mixed> $data */
        $data = $config->toArray();
        $data[ConfigKey::DEPENDENCIES] = $dependencies;

        return new Config($data, $config->environment);
    }

    /** @return static */
    private static function newBuilder(): static
    {
        return new static();
    }

    /** @param callable(ContainerValue, array<string|int, mixed>): mixed $factory */
    public function addFactory(string $id, callable $factory): static
    {
        self::assertBindingIdAvailable($id, 'factory');
        $this->invalidateBindingValidationFor($id, 'factory');
        $this->factories[$id] = $factory;

        return $this;
    }

    /** @param array<array-key, mixed> $factories */
    public function addFactories(array $factories): static
    {
        foreach ($factories as $id => $factory) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException('Factory ids must be strings.');
            }

            if (!is_callable($factory)) {
                throw new InvalidConfigurationException(sprintf(
                    'Factory "%s" must be callable; got %s.',
                    $id,
                    get_debug_type($factory),
                ));
            }

            $this->addFactory($id, $factory);
        }

        return $this;
    }

    public function addInvokable(string $classOrAlias, ?string $class = null): static
    {
        if ($classOrAlias === '' || $class === '') {
            throw new InvalidConfigurationException(
                'Invokable class and alias names must be non-empty strings.',
            );
        }

        $target = $class ?? $classOrAlias;
        InvokableSpecificationValidator::assertValid($target);
        self::assertBindingIdAvailable($target, 'invokable');
        $this->invalidateBindingValidationFor($target, 'invokable');

        if ($class !== null) {
            self::assertBindingIdAvailable($classOrAlias, 'alias');
            DependencyConfiguration::assertInvokableAliasCompatible(
                $this->aliases,
                $classOrAlias,
                $class,
            );
            $this->bindingsValidated = false;
        }

        /** @var class-string $target */
        if (!in_array($target, $this->invokables, true)) {
            $this->invokables[] = $target;
        }

        if ($class !== null) {
            $this->aliases[$classOrAlias] ??= $class;
        }

        return $this;
    }

    /** @param array<array-key, mixed> $invokables */
    public function addInvokables(array $invokables): static
    {
        foreach ($invokables as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidConfigurationException(sprintf(
                    'Invokable entry must be a non-empty class-string; got %s.',
                    get_debug_type($value),
                ));
            }

            is_int($key)
                ? $this->addInvokable($value)
                : $this->addInvokable($key, $value);
        }

        return $this;
    }

    public function addAlias(string $alias, string $target): static
    {
        self::assertBindingIdAvailable($alias, 'alias');

        if ($target === '') {
            throw new InvalidConfigurationException(
                'Alias target must be a non-empty DI id.',
            );
        }

        $this->bindingsValidated = false;
        $this->aliases[$alias] = $target;

        return $this;
    }

    /** @param array<array-key, mixed> $aliases */
    public function addAliases(array $aliases): static
    {
        foreach ($aliases as $alias => $target) {
            if (!is_string($alias)) {
                throw new InvalidConfigurationException('Alias ids must be strings.');
            }

            if (!is_string($target)) {
                throw new InvalidConfigurationException(sprintf(
                    'Alias "%s" target must be a string; got %s.',
                    $alias,
                    get_debug_type($target),
                ));
            }

            $this->addAlias($alias, $target);
        }

        return $this;
    }

    /** @param DelegatorSpecification $delegator */
    public function addDelegator(
        string $id,
        callable|string|array $delegator,
    ): static {
        self::assertBindingIdAvailable($id, 'delegator');
        $normalized = DependencyConfiguration::normalizeDelegatorSpecification(
            $delegator,
            $id,
        );

        if (isset($this->aliases[$id]) || in_array($id, $this->aliases, true)) {
            $this->bindingsValidated = false;
        }

        $this->delegators[$id][] = $normalized;

        return $this;
    }

    /** @param array<array-key, mixed> $delegators */
    public function addDelegators(array $delegators): static
    {
        foreach ($delegators as $id => $list) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException('Delegator ids must be strings.');
            }

            foreach (DependencyConfiguration::normalizeDelegatorList($list, $id) as $delegator) {
                $this->addDelegator($id, $delegator);
            }
        }

        return $this;
    }

    public function addService(string $id, mixed $service): static
    {
        self::assertBindingIdAvailable($id, 'service');
        $this->invalidateBindingValidationFor($id, 'service');
        $this->services[$id] = $service;

        return $this;
    }

    /** @param array<array-key, mixed> $services */
    public function addServices(array $services): static
    {
        foreach ($services as $id => $service) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException('Service ids must be strings.');
            }

            $this->addService($id, $service);
        }

        return $this;
    }

    public function addParameterResolver(
        mixed $resolver,
        int $priority = 0,
    ): static {
        DependencyConfiguration::assertExtensionSpecification(
            $resolver,
            'parameter resolver',
        );

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

    public function addAttributeHandler(mixed $handler): static
    {
        DependencyConfiguration::assertExtensionSpecification(
            $handler,
            'attribute handler',
        );
        $this->attributeHandlers[] = $handler;

        return $this;
    }

    public function replaceAttributeHandlers(bool $replace = true): static
    {
        $this->replaceAttributeHandlers = $replace;

        return $this;
    }
}
