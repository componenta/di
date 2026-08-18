<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
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
use Componenta\DI\Attribute\Handler\Builtin\CastValueTransformer;
use Componenta\DI\Attribute\Handler\Builtin\ConfigValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\CurrentUserValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\EntryIdValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\EnvValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\InitValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\InjectValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\LazyCreationHandler;
use Componenta\DI\Attribute\Handler\Builtin\MakeValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\NoConstructorPolicyHandler;
use Componenta\DI\Attribute\Handler\Builtin\ProxyCreationHandler;
use Componenta\DI\Attribute\Handler\Builtin\RequestValueProvider;
use Componenta\DI\Attribute\Handler\Builtin\SetUpLifecycleHandler;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapRequest;
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
use Componenta\DI\Compile\Factory\CompiledFactoryPipelineFingerprint;
use Componenta\DI\Compile\Factory\CompiledFactoryShardCompiler;
use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver as EntryFactoryResolver;
use Componenta\DI\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Value\Fallback\AutowireValueFallback;
use Componenta\DI\Value\Fallback\DefaultValueFallback;
use Componenta\DI\Value\Fallback\ExplicitValueFallback;
use Componenta\DI\Value\Fallback\MappedValueFallback;
use Componenta\DI\Value\Fallback\NullableValueFallback;
use Componenta\DI\Value\Fallback\PropertyInitialValueFallback;
use Componenta\DI\Value\Fallback\TrustedValueFallback;
use Componenta\DI\Value\ValueFallbackDefinition;
use Componenta\DI\Value\ValueFallbackRegistry;
use Componenta\DI\Value\ValuePipeline;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * v5 composition root: entries + attribute definitions + value fallbacks.
 *
 * @phpstan-consistent-constructor
 */
class ContainerBuilder
{
    public const int CACHE_VERSION = 12;

    /** @var array<string, non-empty-string> */
    private const array DEFAULT_ALIASES = [
        \Componenta\DI\Cache\DiCacheGeneratorInterface::class => \Componenta\DI\Cache\DiCacheGenerator::class,
    ];

    /** @var array<string,mixed> */
    public private(set) array $factories = [];
    /** @var list<class-string> */
    public private(set) array $invokables = [];
    /** @var array<string,non-empty-string> */
    public private(set) array $aliases = self::DEFAULT_ALIASES;
    /** @var array<string, list<callable|string|array{object|string, string}>> */
    public private(set) array $delegators = [];
    /** @var array<string,mixed> */
    public private(set) array $services = [];
    /** @var list<mixed> */
    public private(set) array $attributeDefinitions = [];
    /** @var list<CapabilityPolicy> */
    public private(set) array $attributeCapabilities = [];
    /** @var list<mixed> */
    public private(set) array $valueFallbacks = [];
    public private(set) ?Config $config = null;

    private ?string $compiledFactoryBaseDir = null;

    public static function configure(Config $config): static
    {
        $dependencies = $config->has(ConfigKey::DEPENDENCIES) ? $config->get(ConfigKey::DEPENDENCIES) : [];
        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException('Container dependencies section must be an array.');
        }
        return static::configureWithDependencies($config, $dependencies);
    }

    /** @param array<array-key, mixed> $dependencies */
    public static function configureWithDependencies(Config $config, array $dependencies): static
    {
        $dependencies = DependencyConfiguration::normalize($dependencies, self::DEFAULT_ALIASES);
        $builder = new static();
        $builder->factories = $dependencies[ConfigKey::FACTORIES] ?? [];
        $builder->invokables = $dependencies[ConfigKey::INVOKABLES] ?? [];
        $builder->aliases = $dependencies[ConfigKey::ALIASES] ?? self::DEFAULT_ALIASES;
        $builder->delegators = $dependencies[ConfigKey::DELEGATORS] ?? [];
        $builder->services = $dependencies[ConfigKey::SERVICES] ?? [];
        $builder->attributeDefinitions = array_values($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS] ?? []);
        $builder->attributeCapabilities = array_values($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES] ?? []);
        $builder->valueFallbacks = array_values($dependencies[ConfigKey::VALUE_FALLBACKS] ?? []);
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
     * @param array<array-key, mixed> $dependencies
     * @return array<string, mixed>
     */
    public static function normalizeDependencies(array $dependencies): array
    {
        return DependencyConfiguration::normalize($dependencies, self::DEFAULT_ALIASES);
    }

    public function build(): Container
    {
        $this->assertBindings();

        $config = $this->config ?? new Config([], new Environment([]));
        $environment = $config->environment ?? new Environment([]);
        $attributes = new AttributeDefinitionRegistry();
        $plans = new AttributePlanBuilder($attributes);
        $fallbacks = new ValueFallbackRegistry();
        $values = new ValuePipeline($fallbacks);
        $parameters = new ParametersResolver($plans, $values);
        $proxyFactory = $this->createProxyFactory();
        $objects = new ObjectPipeline($plans, new InstanceCreator($parameters), $values, $proxyFactory);

        $aliases = new AliasResolver([...$this->aliases, ConfigAttribute::KEY => Config::class]);
        $cache = new EntryCache();
        foreach ($this->services as $id => $service) {
            $cache->putBase($aliases->resolve($id), $service);
        }
        if (!$this->hasBinding(CurrentUserProviderInterface::class)) {
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
                $fallbacks,
                $values,
                $parameters,
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
                        ValueFallbackRegistry::class => $fallbacks,
                        ValuePipeline::class => $values,
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
            $fallbacks,
        );
        $bootstrap->initialize($entryResolver, $executor);

        $this->registerBuiltInAttributes($attributes, $container, $executor);
        $this->registerBuiltInFallbacks($fallbacks, $container);

        foreach ($this->attributeCapabilities as $policy) {
            $attributes->defineCapability($policy);
        }
        foreach ($this->attributeDefinitions as $spec) {
            $attributes->register($this->materializeAttributeDefinition($spec, $container));
        }
        foreach ($this->valueFallbacks as $spec) {
            $fallbacks->add($this->materializeValueFallback($spec, $container));
        }

        $attributes->seal();
        $fallbacks->seal();
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
        $container = $this->build();
        $objects = $container->get(ObjectPipeline::class);
        $attributes = $container->get(AttributeDefinitionRegistry::class);
        $fallbacks = $container->get(ValueFallbackRegistry::class);
        if (!$objects instanceof ObjectPipeline
            || !$attributes instanceof AttributeDefinitionRegistry
            || !$fallbacks instanceof ValueFallbackRegistry
        ) {
            throw new InvalidConfigurationException('Runtime compiler services are unavailable.');
        }

        $aliasResolver = new AliasResolver($this->aliases);
        $excluded = array_fill_keys(ProtectedServiceIds::ids(), true);
        foreach ([...array_keys($this->factories), ...array_keys($this->services), ...$this->invokables] as $id) {
            $excluded[$aliasResolver->resolve($id)] = true;
        }

        $classes = (new AutowireClassGraph($this->aliases))->expand($entries, $excluded);
        if ($classes === []) {
            return [];
        }

        return (new CompiledFactoryShardCompiler(
            new FactoryCodeGenerator(),
            CompiledFactoryPipelineFingerprint::calculate($attributes, $fallbacks),
        ))->compile($classes, $directory, $maxShardBytes, $namespace);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->config?->toArray() ?? [];
        $data[ConfigKey::DEPENDENCIES] = array_filter([
            ConfigKey::FACTORIES => $this->factories,
            ConfigKey::INVOKABLES => $this->invokables,
            ConfigKey::ALIASES => $this->aliases,
            ConfigKey::DELEGATORS => $this->delegators,
            ConfigKey::SERVICES => $this->services,
            ConfigKey::ATTRIBUTE_DEFINITIONS => $this->attributeDefinitions,
            ConfigKey::ATTRIBUTE_CAPABILITIES => $this->attributeCapabilities,
            ConfigKey::VALUE_FALLBACKS => $this->valueFallbacks,
        ], static fn(array $section): bool => $section !== []);
        return $data;
    }

    protected function createEntryResolver(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ObjectPipeline $objects,
        CallableExecutorInterface $executor,
        AttributeDefinitionRegistry $attributes,
        ValueFallbackRegistry $fallbacks,
    ): EntryResolverInterface {
        return new CompositeResolver(
            new EntryFactoryResolver(
                $this->factories,
                $container,
                $proxyFactory,
                $objects,
                $executor,
                $attributes,
                $fallbacks,
                $this->compiledFactoryBaseDir,
            ),
            new InvokableResolver($this->invokables),
            new ReflectionResolver($objects),
        );
    }

    private function registerBuiltInAttributes(
        AttributeDefinitionRegistry $registry,
        Container $container,
        CallableExecutorInterface $executor,
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

        $request = new RequestValueProvider($container, $container);
        $providers = [
            ConfigAttribute::class => new ConfigValueProvider($container),
            Env::class => new EnvValueProvider($container),
            EntryId::class => new EntryIdValueProvider($container),
            Inject::class => new InjectValueProvider($container),
            CurrentUser::class => new CurrentUserValueProvider($container),
            Init::class => new InitValueProvider($executor),
            Make::class => new MakeValueProvider($container),
            Header::class => $request,
            Cookie::class => $request,
            QueryParam::class => $request,
            PayloadParam::class => $request,
            RequestAttribute::class => $request,
            ServerParam::class => $request,
            UploadedFile::class => $request,
            MapRequest::class => $request,
        ];
        foreach ($providers as $attribute => $handler) {
            $registry->register(new AttributeDefinition($attribute, $handler, [ValueProvider::class]));
        }

        $registry->register(new AttributeDefinition(Cast::class, new CastValueTransformer($container), [ValueTransformer::class]));
        $registry->register(new AttributeDefinition(Lazy::class, new LazyCreationHandler(), [CreationStrategy::class]));
        $registry->register(new AttributeDefinition(Proxy::class, new ProxyCreationHandler(), [CreationStrategy::class]));
        $registry->register(new AttributeDefinition(NoConstructor::class, new NoConstructorPolicyHandler(), [ConstructorPolicy::class]));
        $registry->register(new AttributeDefinition(SetUp::class, new SetUpLifecycleHandler($executor), [LifecycleHook::class]));
    }

    private function registerBuiltInFallbacks(ValueFallbackRegistry $registry, ContainerInterface $container): void
    {
        $registry->add(new ValueFallbackDefinition('explicit', new ExplicitValueFallback(), before: ['mapped']));
        $registry->add(new ValueFallbackDefinition('mapped', new MappedValueFallback(), after: ['explicit'], before: ['trusted']));
        $registry->add(new ValueFallbackDefinition('trusted', new TrustedValueFallback(), after: ['mapped'], before: ['property_initial']));
        $registry->add(new ValueFallbackDefinition('property_initial', new PropertyInitialValueFallback(), after: ['trusted'], before: ['autowire']));
        $registry->add(new ValueFallbackDefinition('autowire', new AutowireValueFallback($container), after: ['property_initial'], before: ['default']));
        $registry->add(new ValueFallbackDefinition('default', new DefaultValueFallback(), after: ['autowire'], before: ['nullable']));
        $registry->add(new ValueFallbackDefinition('nullable', new NullableValueFallback(), after: ['default']));
    }

    private function materializeAttributeDefinition(mixed $spec, ContainerInterface $container): AttributeDefinition
    {
        $value = $this->materializeExtension($spec, $container);
        if (!$value instanceof AttributeDefinition) {
            throw new InvalidConfigurationException(sprintf('Attribute definition factory returned %s.', get_debug_type($value)));
        }
        return $value;
    }

    private function materializeValueFallback(mixed $spec, ContainerInterface $container): ValueFallbackDefinition
    {
        $value = $this->materializeExtension($spec, $container);
        if (!$value instanceof ValueFallbackDefinition) {
            throw new InvalidConfigurationException(sprintf('Value fallback factory returned %s.', get_debug_type($value)));
        }
        return $value;
    }

    private function materializeExtension(mixed $spec, ContainerInterface $container): mixed
    {
        if ($spec instanceof AttributeDefinition || $spec instanceof ValueFallbackDefinition) {
            return $spec;
        }
        if (is_string($spec)) {
            return $container->get($spec);
        }
        if (is_callable($spec)) {
            return $spec($container);
        }
        throw new InvalidConfigurationException(sprintf('Unsupported extension specification %s.', get_debug_type($spec)));
    }

    protected function createProxyFactory(): ProxyFactoryInterface
    {
        return new ProxyFactory();
    }

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
                throw new InvalidConfigurationException(sprintf('Factory "%s" must be callable or DefinitionInterface.', $id));
            }
        }
        return $this;
    }

    public function addInvokable(string $classOrAlias, ?string $class = null): static
    {
        $target = $class ?? $classOrAlias;
        self::assertId($target, 'invokable');
        if (!class_exists($target)) {
            throw new InvalidConfigurationException(sprintf('Invokable class "%s" does not exist.', $target));
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

    public function addAttributeDefinition(mixed $definition): static
    {
        $this->attributeDefinitions[] = $definition;
        return $this;
    }

    public function defineAttributeCapability(CapabilityPolicy $policy): static
    {
        $this->attributeCapabilities[] = $policy;
        return $this;
    }

    public function addValueFallback(mixed $fallback): static
    {
        $this->valueFallbacks[] = $fallback;
        return $this;
    }

    private function assertBindings(): void
    {
        $aliases = new AliasResolver($this->aliases);
        $owners = [];
        foreach ([
            'factory' => array_keys($this->factories),
            'invokable' => $this->invokables,
            'service' => array_keys($this->services),
        ] as $kind => $ids) {
            foreach ($ids as $id) {
                self::assertId($id, $kind);
                $canonical = $aliases->resolve($id);
                if (ProtectedServiceIds::contains($id) || ProtectedServiceIds::contains($canonical)) {
                    throw new InvalidConfigurationException(sprintf('Cannot register %s for protected DI id "%s".', $kind, $id));
                }
                if (isset($owners[$canonical])) {
                    throw new InvalidConfigurationException(sprintf('Canonical DI id "%s" has multiple bindings.', $canonical));
                }
                $owners[$canonical] = true;
            }
        }
        foreach (array_keys($this->aliases) as $alias) {
            self::assertId($alias, 'alias');
            if (ProtectedServiceIds::contains($alias)) {
                throw new InvalidConfigurationException(sprintf('Cannot replace protected DI alias "%s".', $alias));
            }
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
            throw new InvalidConfigurationException(sprintf('%s id must be non-empty.', ucfirst($kind)));
        }
    }

    /** @param array<string,mixed> $dependencies */
    private static function configWithDependencies(Config $config, array $dependencies): Config
    {
        $data = $config->toArray();
        $data[ConfigKey::DEPENDENCIES] = $dependencies;
        return new Config($data, $config->environment);
    }
}
