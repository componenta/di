<?php

declare(strict_types=1);

namespace Componenta\DI;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverGenerator;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverLoader;
use Componenta\DI\Compile\Entry\GeneratedEntryResolverWriter;
use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
use Componenta\DI\Compile\Parameter\DefaultParameterResolverCodeGenerators;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry;
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
use Componenta\Reflection\Reflection;
use Psr\Container\ContainerInterface;

/** Builds the runtime container and its resolver/attribute pipelines. */
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

    public const int CACHE_VERSION = 5;

    /** @var array<string, string> */
    private const array DEFAULT_ALIASES = [
        \Componenta\DI\Cache\DiCacheGeneratorInterface::class
            => \Componenta\DI\Cache\DiCacheGenerator::class,
    ];


    /** @var array<string, mixed> */
    private(set) array $factories = [];

    /** @var list<class-string> */
    private(set) array $invokables = [];

    /** @var array<string, string> */
    private(set) array $aliases = self::DEFAULT_ALIASES;

    /** @var array<string, list<callable|string|array>> */
    private(set) array $delegators = [];

    /** @var array<string, mixed> */
    private(set) array $services = [];

    /** @var list<array{0: mixed, 1: int}> */
    private(set) array $parameterResolvers = [];

    /** @var list<mixed> */
    private(set) array $attributeHandlers = [];

    private(set) bool $replaceParameterResolvers = false;

    private(set) bool $replaceAttributeHandlers = false;

    private(set) ?string $generatedEntryResolverFile = null;

    private(set) ?string $generatedEntryResolverReleaseFingerprint = null;

    private(set) ?Config $config = null;

    /** @var array<class-string, ParameterResolverInterface&AttributeHandlerInterface>|null */
    private ?array $sharedResolvers = null;

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

        return static::configureWithDependencies($config, $dependencies);
    }

    /** @param array<string, mixed> $dependencies */
    public static function configureWithDependencies(
        Config $config,
        array $dependencies,
    ): static {
        self::assertDependencyShape($dependencies);

        $builder = new static();

        $builder->factories = array_merge(
            $builder->factories,
            $dependencies[ConfigKey::FACTORIES] ?? [],
        );
        $builder->aliases = array_merge(
            $builder->aliases,
            $dependencies[ConfigKey::ALIASES] ?? [],
        );
        $builder->services = $dependencies[ConfigKey::SERVICES] ?? [];

        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $delegatorList) {
            $builder->delegators[$id] = self::normalizeDelegatorList($delegatorList, $id);
        }

        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $key => $value) {
            if (!in_array($value, $builder->invokables, true)) {
                $builder->invokables[] = $value;
            }

            if (is_string($key) && !isset($builder->aliases[$key])) {
                $builder->aliases[$key] = $value;
            }
        }

        foreach ($dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [] as $priority => $resolver) {
            $builder->parameterResolvers[] = [$resolver, $priority];
        }

        foreach ($dependencies[ConfigKey::ATTRIBUTE_HANDLERS] ?? [] as $handler) {
            $builder->attributeHandlers[] = $handler;
        }

        $builder->replaceParameterResolvers = (bool) (
            $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false
        );
        $builder->replaceAttributeHandlers = (bool) (
            $dependencies[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE] ?? false
        );

        $generatedFile = $dependencies[ConfigKey::GENERATED_ENTRY_RESOLVER_FILE] ?? null;
        if (is_string($generatedFile) && $generatedFile !== '') {
            $builder->generatedEntryResolverFile = $generatedFile;
        }
        $generatedRelease = $dependencies[
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
        ] ?? null;
        if (is_string($generatedRelease) && $generatedRelease !== '') {
            $builder->generatedEntryResolverReleaseFingerprint = $generatedRelease;
        }


        $builder->config = self::configWithDependencies($config, $dependencies);

        return $builder;
    }

    /** @param array<string, mixed> $cache */
    public static function configureFromCache(
        Config $config,
        array $cache,
        ?string $baseDir = null,
    ): static {
        if (array_key_exists('version', $cache)
            || array_key_exists(ConfigKey::DEPENDENCIES, $cache)
        ) {
            $version = $cache['version'] ?? self::CACHE_VERSION;
            if ($version !== self::CACHE_VERSION) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container cache version "%s"; expected "%d".',
                    is_scalar($version) ? (string) $version : get_debug_type($version),
                    self::CACHE_VERSION,
                ));
            }

            $dependencies = $cache[ConfigKey::DEPENDENCIES] ?? [];
            if (!is_array($dependencies)) {
                throw new InvalidConfigurationException(
                    'Container cache dependencies section must be an array.',
                );
            }

            return static::configureWithDependencies(
                $config,
                self::resolveDependencyFiles($dependencies, $baseDir),
            );
        }

        return static::configureWithDependencies(
            $config,
            self::resolveDependencyFiles($cache, $baseDir),
        );
    }

    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    public static function normalizeDependencies(array $dependencies): array
    {
        self::assertDependencyShape($dependencies);

        $aliases = array_merge(
            self::DEFAULT_ALIASES,
            $dependencies[ConfigKey::ALIASES] ?? [],
        );
        $invokables = [];

        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $key => $value) {
            if (!in_array($value, $invokables, true)) {
                $invokables[] = $value;
            }

            if (is_string($key) && !isset($aliases[$key])) {
                $aliases[$key] = $value;
            }
        }

        $delegators = [];
        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $list) {
            $delegators[$id] = self::normalizeDelegatorList($list, $id);
        }

        $normalized = [
            ConfigKey::FACTORIES => $dependencies[ConfigKey::FACTORIES] ?? [],
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::ALIASES => $aliases,
            ConfigKey::DELEGATORS => $delegators,
            ConfigKey::SERVICES => $dependencies[ConfigKey::SERVICES] ?? [],
            ConfigKey::PARAMETER_RESOLVERS
                => $dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [],
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => (bool) (
                $dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false
            ),
            ConfigKey::ATTRIBUTE_HANDLERS
                => $dependencies[ConfigKey::ATTRIBUTE_HANDLERS] ?? [],
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE => (bool) (
                $dependencies[ConfigKey::ATTRIBUTE_HANDLERS_REPLACE] ?? false
            ),
            ConfigKey::GENERATED_ENTRY_RESOLVER_FILE
                => $dependencies[ConfigKey::GENERATED_ENTRY_RESOLVER_FILE] ?? null,
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
                => $dependencies[
                    ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
                ] ?? null,
        ];

        return array_filter(
            $normalized,
            static fn (mixed $value): bool => $value !== []
                && $value !== false
                && $value !== null,
        );
    }

    public function build(): Container
    {
        $this->assertNoReservedBindings();
        $this->sharedResolvers = null;

        $entryResolver = null;
        $callableExecutor = null;
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
        $aliases = new AliasResolver(
            [
                ...$this->aliases,
                'config' => Config::class,
            ],
            skipValidation: $this->aliases === self::DEFAULT_ALIASES,
        );
        $proxyFactory = $this->createProxyFactory();

        $container = Reflection::class(Container::class)->newLazyGhost(
            static function (Container $container) use (
                &$entryResolver,
                &$callableExecutor,
                $aliases,
                $proxyFactory,
                $config,
                $environment,
                $parametersResolver,
                $handlerRegistry,
                $attributeProcessor,
            ): void {
                $container->__construct(
                    resolver: $entryResolver,
                    aliases: $aliases,
                    callableExecutor: $callableExecutor,
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

        // Trigger the one-shot lazy constructor only after every captured
        // collaborator has been assigned. Core services are installed atomically
        // inside Container::__construct() and can never be rebound afterwards.
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
        $parametersResolver->seal();
        $handlerRegistry->seal();
        $this->installGeneratedEntryResolver(
            $entryResolver,
            $parametersResolver,
            $attributeProcessor,
            $proxyFactory,
        );

        return $container;
    }

    /**
     * Compiles one generated EntryResolver file from the exact runtime
     * parameter-resolver and attribute-handler pipelines assembled by this
     * builder.
     *
     * @param iterable<class-string> $classes
     * @param ?string $releaseFingerprint Deployment identifier covering both
     *        application sources and DI extension configuration. It must change
     *        whenever either changes. Null keeps strict source hashing on every
     *        generated resolver load.
     */
    public function compileGeneratedEntryResolver(
        iterable $classes,
        string $file,
        ?ParameterResolverCodeGeneratorRegistry $generators = null,
        string $namespace = 'Componenta\\DI\\Generated',
        ?string $releaseFingerprint = null,
    ): string {
        $container = $this->build();
        $parameters = $container->get(ParametersResolver::class);
        $attributes = $container->get(AttributeProcessor::class);

        if (!$parameters instanceof ParametersResolver
            || !$attributes instanceof AttributeProcessor
        ) {
            throw new InvalidConfigurationException(
                'Runtime DI compiler services are unavailable.',
            );
        }

        $generators ??= DefaultParameterResolverCodeGenerators::create();
        $parameterCode = new ParameterCodeGenerator($parameters, $generators);
        $factoryCode = new FactoryCodeGenerator($parameterCode, $attributes);
        $code = (new GeneratedEntryResolverGenerator(
            $factoryCode,
            $parameters,
            $attributes,
            $generators,
        ))->generate($classes, $namespace, $releaseFingerprint);

        (new GeneratedEntryResolverWriter())->write($file, $code);
        $this->generatedEntryResolverFile = $file;
        $this->generatedEntryResolverReleaseFingerprint = $releaseFingerprint;

        return $file;
    }

    public function toArray(): array
    {
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
            ConfigKey::GENERATED_ENTRY_RESOLVER_FILE
                => $this->generatedEntryResolverFile,
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
                => $this->generatedEntryResolverReleaseFingerprint,
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
            ),
            new InvokableResolver(
                $this->invokables,
                $proxyFactory,
            ),
            new ReflectionResolver(
                new InstanceCreator($parametersResolver),
                $attributeProcessor,
                $proxyFactory,
            ),
        );
    }

    protected function installGeneratedEntryResolver(
        EntryResolverInterface $entryResolver,
        ParametersResolver $parametersResolver,
        AttributeProcessor $attributeProcessor,
        ProxyFactoryInterface $proxyFactory,
    ): void {
        if ($this->generatedEntryResolverFile === null
            || !$entryResolver instanceof CompositeResolver
        ) {
            return;
        }

        $generated = (new GeneratedEntryResolverLoader())->load(
            $this->generatedEntryResolverFile,
            $parametersResolver->resolverList,
            $attributeProcessor->registry->handlers,
            $proxyFactory,
            $this->generatedEntryResolverReleaseFingerprint,
        );

        if ($generated === null) {
            return;
        }

        $entryResolver->addResolverBefore(
            $generated,
            ReflectionResolver::class,
        );
    }

    /**
     * Builds both extension pipelines in dependency-safe order.
     *
     * Default parameter resolvers are required to autowire custom handlers;
     * default attribute handlers are required to initialize custom parameter
     * resolvers materialized through the container. Register both default
     * layers before either custom layer so extension services observe the same
     * complete baseline pipeline as ordinary application services.
     */
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
                if (!is_string($id)) {
                    continue;
                }

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
                if (!is_string($id)) {
                    continue;
                }

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

    /**
     * @return array<class-string, ParameterResolverInterface&AttributeHandlerInterface>
     */
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
        if (is_object($config)
            && ($config instanceof ParameterResolverInterface
                || $config instanceof AttributeHandlerInterface)
        ) {
            return $config;
        }

        $extension = match (true) {
            $config instanceof Closure => $config($container),
            is_callable($config) => $config($container),
            is_string($config) => $container->get($config),
            default => throw new InvalidConfigurationException(sprintf(
                'Extension specification must be an instance, callable or service id; got %s.',
                get_debug_type($config),
            )),
        };

        if (!is_object($extension)) {
            throw new InvalidConfigurationException(sprintf(
                'Extension factory returned %s instead of an object.',
                get_debug_type($extension),
            ));
        }

        return $extension;
    }

    /** @param list<array{0: mixed, 1: int}> $resolvers */
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

    /**
     * @return list<callable|string|array>
     */
    private static function normalizeDelegatorList(mixed $value, string $id): array
    {
        $items = self::isCallableArraySpecification($value)
            ? [$value]
            : (is_array($value) && array_is_list($value) ? $value : [$value]);

        foreach ($items as $delegator) {
            self::assertDelegatorSpecification($delegator, $id);
        }

        /** @var list<callable|string|array> $items */
        return array_values($items);
    }

    private static function assertDelegatorSpecification(mixed $delegator, string $id): void
    {
        if (is_callable($delegator)
            || is_string($delegator)
            || self::isCallableArraySpecification($delegator)
        ) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            'Delegator for "%s" must be callable, string or [class|object, method]; got %s.',
            $id,
            get_debug_type($delegator),
        ));
    }

    private static function assertExtensionSpecification(mixed $extension, string $kind): void
    {
        if ($extension instanceof ParameterResolverInterface
            || $extension instanceof AttributeHandlerInterface
            || $extension instanceof Closure
            || is_callable($extension)
            || (is_string($extension) && $extension !== '')
        ) {
            return;
        }

        throw new InvalidConfigurationException(sprintf(
            '%s specification must be an instance, callable or non-empty service id; got %s.',
            ucfirst($kind),
            get_debug_type($extension),
        ));
    }

    private static function isCallableArraySpecification(mixed $value): bool
    {
        if (!is_array($value)
            || array_keys($value) !== [0, 1]
            || !is_string($value[1])
        ) {
            return false;
        }

        if (is_callable($value)) {
            return true;
        }

        // A non-static [Class::class, method] pair is resolved through the
        // container later and is therefore not directly callable yet.
        return is_string($value[0])
            && class_exists($value[0])
            && method_exists($value[0], $value[1]);
    }

    /** @param array<string, mixed> $dependencies */
    private static function assertDependencyShape(array $dependencies): void
    {
        $allowed = array_fill_keys(ConfigKey::dependencyKeys(), true);

        foreach ($dependencies as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidConfigurationException(sprintf(
                    'Unsupported container dependency key "%s".',
                    is_scalar($key) ? (string) $key : get_debug_type($key),
                ));
            }
        }

        foreach ([
            ConfigKey::FACTORIES,
            ConfigKey::INVOKABLES,
            ConfigKey::ALIASES,
            ConfigKey::DELEGATORS,
            ConfigKey::SERVICES,
            ConfigKey::PARAMETER_RESOLVERS,
            ConfigKey::ATTRIBUTE_HANDLERS,
        ] as $key) {
            if (array_key_exists($key, $dependencies)
                && !is_array($dependencies[$key])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be an array; got %s.',
                    $key,
                    get_debug_type($dependencies[$key]),
                ));
            }
        }

        foreach ([
            ConfigKey::PARAMETER_RESOLVERS_REPLACE,
            ConfigKey::ATTRIBUTE_HANDLERS_REPLACE,
        ] as $key) {
            if (array_key_exists($key, $dependencies)
                && !is_bool($dependencies[$key])
            ) {
                throw new InvalidConfigurationException(sprintf(
                    'Container dependency "%s" must be bool; got %s.',
                    $key,
                    get_debug_type($dependencies[$key]),
                ));
            }
        }

        $file = $dependencies[ConfigKey::GENERATED_ENTRY_RESOLVER_FILE] ?? null;
        if ($file !== null && (!is_string($file) || $file === '')) {
            throw new InvalidConfigurationException(sprintf(
                'Container dependency "%s" must be null or a non-empty string; got %s.',
                ConfigKey::GENERATED_ENTRY_RESOLVER_FILE,
                get_debug_type($file),
            ));
        }

        $releaseFingerprint = $dependencies[
            ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
        ] ?? null;
        if ($releaseFingerprint !== null
            && (!is_string($releaseFingerprint) || $releaseFingerprint === '')
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Container dependency "%s" must be null or a non-empty string; got %s.',
                ConfigKey::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT,
                get_debug_type($releaseFingerprint),
            ));
        }

        foreach ($dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [] as $priority => $resolver) {
            if (!is_int($priority)) {
                throw new InvalidConfigurationException(sprintf(
                    'Parameter resolver priority must be int; got %s.',
                    get_debug_type($priority),
                ));
            }

            self::assertExtensionSpecification($resolver, 'parameter resolver');
        }

        $handlers = $dependencies[ConfigKey::ATTRIBUTE_HANDLERS] ?? [];
        if ($handlers !== [] && !array_is_list($handlers)) {
            throw new InvalidConfigurationException(
                'Attribute handlers must be configured as a list in registration order.',
            );
        }

        foreach ($handlers as $handler) {
            self::assertExtensionSpecification($handler, 'attribute handler');
        }

        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException(sprintf(
                    'Invokable entry must be a non-empty class-string; got %s.',
                    get_debug_type($class),
                ));
            }
        }

        foreach ($dependencies[ConfigKey::ALIASES] ?? [] as $alias => $target) {
            if (!is_string($alias) || $alias === ''
                || !is_string($target) || $target === ''
            ) {
                throw new InvalidConfigurationException(
                    'Aliases must map non-empty string ids to non-empty string targets.',
                );
            }
        }

        foreach ([
            ConfigKey::FACTORIES,
            ConfigKey::DELEGATORS,
            ConfigKey::SERVICES,
        ] as $key) {
            foreach ($dependencies[$key] ?? [] as $id => $_value) {
                if (!is_string($id) || $id === '') {
                    throw new InvalidConfigurationException(sprintf(
                        'Container dependency "%s" requires non-empty string ids.',
                        $key,
                    ));
                }
            }
        }
    }

    /** @param array<string, mixed> $dependencies */
    private static function configWithDependencies(
        Config $config,
        array $dependencies,
    ): Config {
        $data = $config->toArray();
        $data[ConfigKey::DEPENDENCIES] = $dependencies;

        return new Config($data, $config->environment);
    }

    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private static function resolveDependencyFiles(
        array $dependencies,
        ?string $baseDir,
    ): array {
        if ($baseDir === null) {
            return $dependencies;
        }

        foreach ([ConfigKey::GENERATED_ENTRY_RESOLVER_FILE] as $key) {
            $file = $dependencies[$key] ?? null;

            if (!is_string($file)
                || $file === ''
                || self::isAbsolutePath($file)
            ) {
                continue;
            }

            $dependencies[$key]
                = rtrim($baseDir, '/\\') . '/' . ltrim($file, '/\\');
        }

        return $dependencies;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && ($path[0] === '/'
                || $path[0] === '\\'
                || (strlen($path) >= 3
                    && ctype_alpha($path[0])
                    && $path[1] === ':'));
    }

    /** @param callable(ContainerValue, array<string|int, mixed>):mixed $factory */
    public function addFactory(string $id, callable $factory): static
    {
        self::assertBindingIdAvailable($id, 'factory');
        $this->factories[$id] = $factory;
        return $this;
    }

    public function addFactories(array $factories): static
    {
        foreach ($factories as $id => $factory) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException(
                    'Factory ids must be strings.',
                );
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
        self::assertBindingIdAvailable($target, 'invokable');

        if ($class !== null) {
            self::assertBindingIdAvailable($classOrAlias, 'alias');
        }

        if (!in_array($target, $this->invokables, true)) {
            $this->invokables[] = $target;
        }

        if ($class !== null && !isset($this->aliases[$classOrAlias])) {
            $this->aliases[$classOrAlias] = $class;
        }

        return $this;
    }

    public function addInvokables(array $invokables): static
    {
        foreach ($invokables as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidConfigurationException(sprintf(
                    'Invokable entry must be a non-empty class-string; got %s.',
                    get_debug_type($value),
                ));
            }

            if (!is_int($key) && !is_string($key)) {
                throw new InvalidConfigurationException(
                    'Invokable aliases must be strings.',
                );
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

        $this->aliases[$alias] = $target;
        return $this;
    }

    public function addAliases(array $aliases): static
    {
        foreach ($aliases as $alias => $target) {
            if (!is_string($alias)) {
                throw new InvalidConfigurationException(
                    'Alias ids must be strings.',
                );
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

    public function addDelegator(
        string $id,
        callable|string|array $delegator,
    ): static {
        self::assertBindingIdAvailable($id, 'delegator');
        self::assertDelegatorSpecification($delegator, $id);
        $this->delegators[$id][] = $delegator;
        return $this;
    }

    public function addDelegators(array $delegators): static
    {
        foreach ($delegators as $id => $list) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException(
                    'Delegator ids must be strings.',
                );
            }

            foreach (self::normalizeDelegatorList($list, $id) as $delegator) {
                $this->addDelegator($id, $delegator);
            }
        }

        return $this;
    }

    public function addService(string $id, mixed $service): static
    {
        self::assertBindingIdAvailable($id, 'service');
        $this->services[$id] = $service;
        return $this;
    }

    public function addServices(array $services): static
    {
        foreach ($services as $id => $service) {
            if (!is_string($id)) {
                throw new InvalidConfigurationException(
                    'Service ids must be strings.',
                );
            }

            $this->addService($id, $service);
        }

        return $this;
    }

    /**
     * Installs a generated resolver. A release fingerprint avoids source-file
     * hashing during build; it must be the same value used during compilation
     * and must change with application sources or DI extension configuration.
     * Null preserves strict runtime source validation.
     */
    public function useGeneratedEntryResolver(
        ?string $file,
        ?string $releaseFingerprint = null,
    ): static
    {
        if ($file === '') {
            throw new InvalidConfigurationException(
                'Generated entry resolver path must be null or a non-empty string.',
            );
        }
        if ($releaseFingerprint === '') {
            throw new InvalidConfigurationException(
                'Generated entry resolver release fingerprint must be null or a non-empty string.',
            );
        }

        $this->generatedEntryResolverFile = $file;
        $this->generatedEntryResolverReleaseFingerprint = $releaseFingerprint;

        return $this;
    }

    public function addParameterResolver(
        mixed $resolver,
        int $priority = 0,
    ): static {
        self::assertExtensionSpecification($resolver, 'parameter resolver');

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
        self::assertExtensionSpecification($handler, 'attribute handler');
        $this->attributeHandlers[] = $handler;
        return $this;
    }

    public function replaceAttributeHandlers(bool $replace = true): static
    {
        $this->replaceAttributeHandlers = $replace;
        return $this;
    }
}
