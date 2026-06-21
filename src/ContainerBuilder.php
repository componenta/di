<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\FilePlanProvider;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\ConfigAttributeResolver;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver as EntryFactoryResolver;
use Componenta\DI\Resolver\MakeAttributeResolver;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Entry\PropertyInjector;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Entry\SetUp\ConfigUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\ContainerValueUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EntryIdUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EnvUnwrapper;
use Componenta\DI\Resolver\Entry\SetUpRunner;
use Componenta\DI\Resolver\EntryIdResolver;
use Componenta\DI\Resolver\EnvResolver;
use Componenta\DI\Resolver\Parameter\ArrayResolver as ParameterArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Property\ArrayResolver as PropertyArrayResolver;
use Componenta\DI\Resolver\Property\InitResolver;
use Componenta\DI\Resolver\Property\InjectResolver;
use Componenta\DI\Resolver\Property\PropertiesResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\Reflection\Reflection;
use Closure;
use Psr\Container\ContainerInterface;

/**
 * Builds Container instances with configurable resolver chains.
 *
 * @example From Config instance
 * ```php
 * $config = new Config([
 *     ConfigKey::DEPENDENCIES => [
 *         ConfigKey::FACTORIES => [LoggerInterface::class => LoggerFactory::class],
 *         ConfigKey::ALIASES => ['logger' => LoggerInterface::class],
 *     ],
 *     'database' => ['host' => 'localhost'],
 * ]);
 * $container = ContainerBuilder::configure($config)->build();
 * ```
 *
 * @example Fluent API
 * ```php
 * $container = (new ContainerBuilder())
 *     ->addFactory(LoggerInterface::class, fn($c) => new FileLogger())
 *     ->addAlias('logger', LoggerInterface::class)
 *     ->build();
 * ```
 *
 * @example Resolver chain customisation
 * ```php
 * // Add custom resolvers via Config:
 * ConfigKey::DEPENDENCIES => [
 *     ConfigKey::PARAMETER_RESOLVERS => [
 *         500 => MyResolver::class,
 *     ],
 *     ConfigKey::PARAMETER_RESOLVERS_REPLACE => true, // optional: skip defaults
 * ];
 * ```
 */
class ContainerBuilder
{
    // =========================================================================
    // Default chain priorities
    //
    // Higher = earlier (PriorityList sorts DESC by default; equal priorities
    // preserve insertion order). Constants are spaced by 100 so consumers can
    // wedge a custom resolver between two defaults via an explicit priority,
    // e.g. ConfigKey::PARAMETER_RESOLVERS => [self::PRIORITY_PARAM_AUTOWIRE + 50 => $r].
    // =========================================================================

    public const int PRIORITY_PARAM_CASTABLE         = 1200;
    public const int PRIORITY_PARAM_ARRAY            = 1100;
    public const int PRIORITY_PARAM_ARRAY_TYPED      = 1000;
    public const int PRIORITY_PARAM_CURRENT_USER     = 900;
    public const int PRIORITY_PARAM_REQUEST          = 800;
    public const int PRIORITY_PARAM_MAKE             = 700;
    public const int PRIORITY_PARAM_ENV              = 600;
    public const int PRIORITY_PARAM_ENTRY_ID         = 500;
    public const int PRIORITY_PARAM_CONFIG           = 400;
    public const int PRIORITY_PARAM_AUTOWIRE         = 300;
    public const int PRIORITY_PARAM_DEFAULT_VALUE    = 200;
    public const int PRIORITY_PARAM_NULLABLE         = 100;

    public const int PRIORITY_PROP_CASTABLE          = 900;
    public const int PRIORITY_PROP_ARRAY             = 800;
    public const int PRIORITY_PROP_CURRENT_USER      = 700;
    public const int PRIORITY_PROP_INIT              = 600;
    public const int PRIORITY_PROP_MAKE              = 500;
    public const int PRIORITY_PROP_ENV               = 400;
    public const int PRIORITY_PROP_ENTRY_ID          = 300;
    public const int PRIORITY_PROP_INJECT            = 200;
    public const int PRIORITY_PROP_CONFIG            = 100;

    public const int CACHE_VERSION = 1;

    /**
     * @var array<string, string>
     */
    private const array DEFAULT_ALIASES = [
        \Componenta\DI\Cache\DiCacheGeneratorInterface::class => \Componenta\DI\Cache\DiCacheGenerator::class,
    ];

    /** @var array<string, callable(ContainerValue, array<string|int, mixed>):mixed|string|array{0: string, 1: string}|\Componenta\DI\Definition\FactoryDefinition|\Componenta\DI\Definition\ClassDefinition> */
    private(set) array $factories = [];

    /** @var list<class-string> */
    private(set) array $invokables = [];

    /** @var list<class-string> */
    private(set) array $autowires = [];

    /**
     * Interface -> concrete defaults that every container needs but which
     * application ConfigProviders rarely override (they may, via
     * {@see addAlias()}). Listed here so the container is usable
     * out-of-the-box without boilerplate.
     *
     * @var array<string, string>
     */
    private(set) array $aliases = self::DEFAULT_ALIASES;

    /** @var array<string, list<callable|string|array>> */
    private(set) array $delegators = [];

    /** @var array<string, mixed> */
    private(set) array $services = [];

    /** @var list<array{0: callable|string, 1: int}> Anonymous parameter resolver additions (no slot). */
    private(set) array $parameterResolvers = [];

    /** @var list<array{0: callable|string, 1: int}> Anonymous property resolver additions (no slot). */
    private(set) array $propertyResolvers = [];

    /** When true, the default parameter chain is skipped - only user resolvers run. */
    private(set) bool $replaceParameterResolvers = false;

    /** When true, the default property chain is skipped - only user resolvers run. */
    private(set) bool $replacePropertyResolvers = false;

    private(set) ?Config $config = null;

    /**
     * Compiled DI plans pre-computed offline by {@see PlanCompiler}.
     * Empty when the consumer hasn't run `discovery:compile` (or hasn't
     * supplied a section under `dependencies.di_plans` in config) - in
     * which case the runtime falls back to the full resolver chain.
     *
     * @var array{param?: array, prop?: array}
     */
    private(set) array $diPlans = [];

    /**
     * Sidecar file with compiled DI plans. When present, plans are loaded by
     * {@see FilePlanProvider} only on first resolver use.
     */
    private(set) ?string $diPlansFile = null;

    /**
     * Precomputed `plan kind -> resolver class` map generated alongside DI
     * plans. Empty when absent or when the runtime should use the legacy
     * scan-and-bind dispatcher assembly.
     *
     * @var array{param?: array<string, class-string>, prop?: array<string, class-string>}
     */
    private(set) array $diPlanDispatcherMap = [];

    /**
     * Per-container-build cache of stateless resolvers shared by both the
     * parameter and property chains. The cache is keyed by the container
     * instance used for the current assembly so compile-time resolver chains
     * cannot leak into a later runtime container built by the same builder.
     *
     * @var array<class-string, ParameterResolverInterface|PropertyResolverInterface>|null
     */
    private ?array $sharedResolvers = null;

    private ?int $sharedResolversContainerId = null;

    /**
     * Creates builder from Config instance.
     *
     * Extracts 'dependencies' section for container configuration.
     * Creates new Config without 'dependencies' for application config,
     * preserving the Environment.
     *
     * @param Config $config Configuration with 'dependencies' section.
     */
    public static function configure(Config $config): static
    {
        // Extract dependencies section
        $dependencies = $config->has(ConfigKey::DEPENDENCIES)
            ? $config->get(ConfigKey::DEPENDENCIES)
            : [];

        if (!is_array($dependencies)) {
            throw new InvalidConfigurationException('Container dependencies section must be an array.');
        }

        return static::configureWithDependencies($config, $dependencies);
    }

    /**
     * Creates builder from a preloaded dependencies section.
     *
     * Used by production `container.cache.php`: the full Config still gets
     * registered as a service, while the container wiring itself can come from
     * a smaller, purpose-built cache file.
     *
     * @param array<string, mixed> $dependencies Normalized or raw dependencies section.
     */
    public static function configureWithDependencies(Config $config, array $dependencies): static
    {
        $builder = new static();

        // User-provided config always wins over builder defaults ($builder->aliases
        // is pre-seeded with core aliases; keys from config override them).
        $builder->factories = array_merge($builder->factories, $dependencies[ConfigKey::FACTORIES] ?? []);
        $builder->aliases = array_merge($builder->aliases, $dependencies[ConfigKey::ALIASES] ?? []);
        $builder->services = array_merge($dependencies[ConfigKey::SERVICES] ?? [], [Environment::class => $config->environment]);
        $builder->autowires = $dependencies[ConfigKey::AUTOWIRES] ?? [];

        // Normalize delegators to list format
        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $delegatorList) {
            $builder->delegators[$id] = is_array($delegatorList) && array_is_list($delegatorList)
                ? $delegatorList
                : [$delegatorList];
        }

        // Normalize invokables: extract aliases from keyed entries.
        // Explicit ALIASES already configured on the builder take precedence:
        // an invokable alias is only registered when no explicit alias exists
        // for the same key.
        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $key => $value) {
            $builder->invokables[] = $value;
            if (is_string($key) && !isset($builder->aliases[$key])) {
                $builder->aliases[$key] = $value;
            }
        }

        // Compiled DI plans (optional - produced offline by `discovery:compile`).
        if (isset($dependencies[PlanCompiler::CONFIG_KEY]) && is_array($dependencies[PlanCompiler::CONFIG_KEY])) {
            $builder->diPlans = $dependencies[PlanCompiler::CONFIG_KEY];
        } elseif (isset($dependencies[PlanCompiler::FILE_CONFIG_KEY])
            && is_string($dependencies[PlanCompiler::FILE_CONFIG_KEY])
            && $dependencies[PlanCompiler::FILE_CONFIG_KEY] !== ''
        ) {
            $builder->diPlansFile = $dependencies[PlanCompiler::FILE_CONFIG_KEY];
        }

        if (isset($dependencies[PlanDispatcher::CONFIG_KEY]) && is_array($dependencies[PlanDispatcher::CONFIG_KEY])) {
            $builder->diPlanDispatcherMap = $dependencies[PlanDispatcher::CONFIG_KEY];
        }

        // Custom parameter/property resolvers
        // Normalize user input [priority => resolver] into the internal list-of-pairs.
        foreach ($dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [] as $priority => $resolverConfig) {
            $builder->parameterResolvers[] = [$resolverConfig, (int) $priority];
        }
        foreach ($dependencies[ConfigKey::PROPERTY_RESOLVERS] ?? [] as $priority => $resolverConfig) {
            $builder->propertyResolvers[] = [$resolverConfig, (int) $priority];
        }

        $builder->replaceParameterResolvers = (bool) ($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false);
        $builder->replacePropertyResolvers  = (bool) ($dependencies[ConfigKey::PROPERTY_RESOLVERS_REPLACE] ?? false);

        // Keep the full Config shape visible through the container service.
        // Production can load a slim `config.cache.php` without dependencies;
        // when the builder receives dependencies from `container.cache.php`,
        // reattach them here so Config consumers keep seeing the historical
        // shape without forcing the config cache itself to duplicate DI data.
        $builder->config = self::configWithDependencies($config, $dependencies);

        return $builder;
    }

    /**
     * Creates builder from a `container.cache.php` payload.
     *
     * Accepted cache shapes:
     *  - `['version' => 1, 'dependencies' => [...]]` (current generated format)
     *  - raw dependencies array (kept as a compatibility escape hatch)
     *
     * @param array<string, mixed> $cache
     */
    public static function configureFromCache(Config $config, array $cache, ?string $baseDir = null): static
    {
        if (array_key_exists('version', $cache) || array_key_exists(ConfigKey::DEPENDENCIES, $cache)) {
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
                throw new InvalidConfigurationException('Container cache dependencies section must be an array.');
            }

            return static::configureWithDependencies($config, self::resolveDependencyFiles($dependencies, $baseDir));
        }

        return static::configureWithDependencies($config, self::resolveDependencyFiles($cache, $baseDir));
    }

    /**
     * Normalizes declarative DI dependencies for `container.cache.php`.
     *
     * Runtime services such as {@see Environment} are intentionally excluded:
     * they are rebound from the live {@see Config} during
     * {@see configureWithDependencies()} so the cache can be reused across
     * deployment environments without freezing build-time env values.
     *
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    public static function normalizeDependencies(array $dependencies): array
    {
        $aliases = array_merge(self::DEFAULT_ALIASES, $dependencies[ConfigKey::ALIASES] ?? []);
        $invokables = [];

        foreach ($dependencies[ConfigKey::INVOKABLES] ?? [] as $key => $value) {
            $invokables[] = $value;
            if (is_string($key) && !isset($aliases[$key])) {
                $aliases[$key] = $value;
            }
        }

        $delegators = [];
        foreach ($dependencies[ConfigKey::DELEGATORS] ?? [] as $id => $delegatorList) {
            $delegators[$id] = is_array($delegatorList) && array_is_list($delegatorList)
                ? $delegatorList
                : [$delegatorList];
        }

        $normalized = [
            ConfigKey::FACTORIES => $dependencies[ConfigKey::FACTORIES] ?? [],
            ConfigKey::INVOKABLES => $invokables,
            ConfigKey::AUTOWIRES => $dependencies[ConfigKey::AUTOWIRES] ?? [],
            ConfigKey::ALIASES => $aliases,
            ConfigKey::DELEGATORS => $delegators,
            ConfigKey::SERVICES => $dependencies[ConfigKey::SERVICES] ?? [],
            ConfigKey::PARAMETER_RESOLVERS => $dependencies[ConfigKey::PARAMETER_RESOLVERS] ?? [],
            ConfigKey::PROPERTY_RESOLVERS => $dependencies[ConfigKey::PROPERTY_RESOLVERS] ?? [],
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => (bool) ($dependencies[ConfigKey::PARAMETER_RESOLVERS_REPLACE] ?? false),
            ConfigKey::PROPERTY_RESOLVERS_REPLACE => (bool) ($dependencies[ConfigKey::PROPERTY_RESOLVERS_REPLACE] ?? false),
        ];

        if (isset($dependencies[PlanCompiler::CONFIG_KEY]) && is_array($dependencies[PlanCompiler::CONFIG_KEY])) {
            $normalized[PlanCompiler::CONFIG_KEY] = $dependencies[PlanCompiler::CONFIG_KEY];
        } elseif (isset($dependencies[PlanCompiler::FILE_CONFIG_KEY])
            && is_string($dependencies[PlanCompiler::FILE_CONFIG_KEY])
            && $dependencies[PlanCompiler::FILE_CONFIG_KEY] !== ''
        ) {
            $normalized[PlanCompiler::FILE_CONFIG_KEY] = $dependencies[PlanCompiler::FILE_CONFIG_KEY];
        }

        if (isset($dependencies[PlanDispatcher::CONFIG_KEY]) && is_array($dependencies[PlanDispatcher::CONFIG_KEY])) {
            $normalized[PlanDispatcher::CONFIG_KEY] = $dependencies[PlanDispatcher::CONFIG_KEY];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $dependencies
     */
    private static function configWithDependencies(Config $config, array $dependencies): Config
    {
        if ($config->has(ConfigKey::DEPENDENCIES)) {
            return $config;
        }

        $data = $config->toArray();
        $data[ConfigKey::DEPENDENCIES] = $dependencies;

        return new Config($data, $config->environment);
    }

    /**
     * @param array<string, mixed> $dependencies
     * @return array<string, mixed>
     */
    private static function resolveDependencyFiles(array $dependencies, ?string $baseDir): array
    {
        if ($baseDir === null
            || !isset($dependencies[PlanCompiler::FILE_CONFIG_KEY])
            || !is_string($dependencies[PlanCompiler::FILE_CONFIG_KEY])
        ) {
            return $dependencies;
        }

        $file = $dependencies[PlanCompiler::FILE_CONFIG_KEY];

        if ($file === '' || self::isAbsolutePath($file)) {
            return $dependencies;
        }

        $dependencies[PlanCompiler::FILE_CONFIG_KEY] = rtrim($baseDir, '/\\') . '/' . ltrim($file, '/\\');

        return $dependencies;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path !== ''
            && (
                $path[0] === '/'
                || $path[0] === '\\'
                || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':')
            );
    }

    /**
     * Builds the container.
     *
     * Construction is strictly constructor-injected: the core collaborators
     * ({@see AliasResolver}, {@see CallableResolver}, {@see CallableExecutor},
     * {@see ParametersResolver}, {@see PropertiesResolver}) are wired here and
     * handed to the {@see Container} ctor. Classes that need the container
     * itself (factory resolvers, SetUp unwrappers, user factories) receive a
     * {@see \ReflectionClass::newLazyGhost() lazy-ghost} Container whose
     * constructor runs in-place on first observable access - by which time
     * the collaborator graph captured by reference is fully assembled.
     */
    public function build(): Container
    {
        $entryResolver    = null;
        $callableExecutor = null;
        $aliases          = new AliasResolver($this->aliases);

        $container = Reflection::class(Container::class)->newLazyGhost(
            static function (Container $container) use (&$entryResolver, &$callableExecutor, $aliases): void {
                $container->__construct($entryResolver, $aliases, $callableExecutor);
            },
        );

        $parametersResolver = new ParametersResolver();
        $propertiesResolver = new PropertiesResolver();

        $callableResolver = new CallableResolver($container);
        $callableExecutor = new CallableExecutor($callableResolver, $parametersResolver);

        $entryResolver = $this->createEntryResolver(
            $parametersResolver,
            $propertiesResolver,
            $container,
        );

        // Register core services FIRST so user-supplied bindings (caster
        // provider, validation provider, ...) are visible to fill*Resolver,
        // which resolves them via $container->get(...) while assembling the
        // default chains. The first set() below is also what triggers the
        // lazy-ghost initializer - $entryResolver and $callableExecutor are
        // both assigned by this point.
        $container->set(Config::class, $this->config ?? new Config([]));
        $container->alias('config', Config::class);
        $container->set(ParametersResolver::class, $parametersResolver);
        $container->set(PropertiesResolver::class, $propertiesResolver);

        foreach ($this->services as $id => $service) {
            $container->set($id, $service);
        }

        $config = $container->get(Config::class);
        if (!$config instanceof Config) {
            throw new InvalidConfigurationException(sprintf(
                'Service "%s" must be an instance of %s.',
                Config::class,
                Config::class,
            ));
        }

        $container->set(ContainerValue::class, new ContainerValue($container, $config));

        foreach ($this->delegators as $id => $delegatorList) {
            foreach ($delegatorList as $delegator) {
                $container->delegator($id, $delegator);
            }
        }

        $this->fillParametersResolver($parametersResolver, $container);
        $this->fillPropertiesResolver($propertiesResolver, $container);

        if ($this->diPlans !== [] || $this->diPlansFile !== null) {
            $dispatcher = $this->buildPlanDispatcher($parametersResolver, $propertiesResolver);

            if ($this->diPlansFile !== null && $this->diPlans === []) {
                $provider = new FilePlanProvider($this->diPlansFile);
                $parametersResolver->setCompiledPlanProvider($provider, $dispatcher);
                $propertiesResolver->setCompiledPlanProvider($provider, $dispatcher);
            } else {
                $parametersResolver->setCompiledPlans($this->diPlans['param'] ?? [], $dispatcher);
                $propertiesResolver->setCompiledPlans($this->diPlans['prop'] ?? [], $dispatcher);
            }
        }

        return $container;
    }

    /**
     * Builds the plan dispatcher by walking the already-assembled
     * parameter and property chains and auto-binding every resolver that
     * implements {@see AttributeMatcherInterface}.
     *
     * Open/Closed: a user-supplied matcher-aware resolver registered through
     * {@see ConfigKey::PARAMETER_RESOLVERS} / {@see ConfigKey::PROPERTY_RESOLVERS}
     * participates automatically - the dispatcher needs no further wiring.
     */
    private function buildPlanDispatcher(
        ParametersResolver $params,
        PropertiesResolver $props,
    ): PlanDispatcher {
        if ($this->diPlanDispatcherMap !== []) {
            $cached = PlanDispatcher::fromKindMap(
                $this->diPlanDispatcherMap,
                $params->resolvers,
                $props->resolvers,
            );

            if ($cached !== null) {
                return $cached;
            }
        }

        $dispatcher = new PlanDispatcher();

        foreach ($params->resolvers as $r) {
            if ($r instanceof AttributeMatcherInterface) {
                $dispatcher->bind($r);
            }
        }

        foreach ($props->resolvers as $r) {
            if ($r instanceof AttributeMatcherInterface) {
                $dispatcher->bind($r);
            }
        }

        return $dispatcher;
    }

    /**
     * One-shot offline-compile helper for callers without a discovery layer.
     *
     * Wraps {@see getPlanCompilers()} + {@see PlanCompiler::compile()} so a
     * standalone consumer can produce plans from any class-list source - a
     * literal array, their own scanner, a Composer classmap, etc. - without
     * touching the compiler internals.
     *
     * Typical wiring:
     * ```php
     * $builder = ContainerBuilder::configure($appConfig);
     * $plans   = $builder->compilePlans([UserService::class, ...]);
     *
     * $config  = new Config([
     *     ConfigKey::DEPENDENCIES => [DiPlanCompiler::CONFIG_KEY => $plans],
     *     ...$appConfig->toArray(),
     * ], $appConfig->environment);
     *
     * $container = ContainerBuilder::configure($config)->build();
     * ```
     *
     * Discovery is optional - projects that ship one (e.g. {@see \Componenta\App\Discovery\Discovery})
     * just feed its result into `$classes`. Projects that don't can hand-pick
     * the classes they care about most (typically every autowire / invokable
     * target plus high-traffic factory output classes).
     *
     * @param iterable<class-string> $classes
     * @return array{param: array<class-string, array<string, array<int, string>>>, prop: array<class-string, array<string, string>>}
     */
    public function compilePlans(iterable $classes, string $mode = PlanCompiler::MODE_SPARSE): array
    {
        $matchers = $this->getPlanCompilers();

        return new PlanCompiler($matchers['param'], $matchers['prop'], $mode)->compile($classes);
    }

    /**
     * Returns the matcher-aware resolvers (in priority order) that the
     * offline compiler should consult. Uses the same chains the runtime
     * uses, so user-supplied resolvers - including pure compile-time ones
     * with no runtime work - are picked up automatically.
     *
     * @return array{param: list<AttributeMatcherInterface>, prop: list<AttributeMatcherInterface>}
     */
    public function getPlanCompilers(): array
    {
        $entryResolver    = null;
        $callableExecutor = null;
        $aliases          = new AliasResolver($this->aliases);

        $container = Reflection::class(Container::class)->newLazyGhost(
            static function (Container $container) use (&$entryResolver, &$callableExecutor, $aliases): void {
                $container->__construct($entryResolver, $aliases, $callableExecutor);
            },
        );

        $parametersResolver = new ParametersResolver();
        $propertiesResolver = new PropertiesResolver();

        $callableResolver = new CallableResolver($container);
        $callableExecutor = new CallableExecutor($callableResolver, $parametersResolver);

        $entryResolver = $this->createEntryResolver(
            $parametersResolver,
            $propertiesResolver,
            $container,
        );

        $container->set(Config::class, $this->config ?? new Config([]));
        $container->alias('config', Config::class);
        $container->set(ParametersResolver::class, $parametersResolver);

        foreach ($this->services as $id => $service) {
            $container->set($id, $service);
        }

        $config = $container->get(Config::class);
        if (!$config instanceof Config) {
            throw new InvalidConfigurationException(sprintf(
                'Service "%s" must be an instance of %s.',
                Config::class,
                Config::class,
            ));
        }

        $container->set(ContainerValue::class, new ContainerValue($container, $config));

        $this->fillParametersResolver($parametersResolver, $container);
        $this->fillPropertiesResolver($propertiesResolver, $container);

        $extract = static function (iterable $chain): array {
            $out = [];
            foreach ($chain as $r) {
                if ($r instanceof AttributeMatcherInterface) {
                    $out[] = $r;
                }
            }
            return $out;
        };

        return [
            'param' => $extract($parametersResolver->resolvers),
            'prop'  => $extract($propertiesResolver->resolvers),
        ];
    }

    /**
     * Returns current configuration as array.
     *
     * Resolver lists are rendered as `[priority => resolver]` for
     * config-compatible round-tripping. When the same priority is registered
     * more than once, the last registration wins in this output - all entries
     * remain in effect at build time.
     */
    public function toArray(): array
    {
        $data = $this->config?->toArray() ?? [];

        $data[ConfigKey::DEPENDENCIES] = [
                ConfigKey::FACTORIES => $this->factories,
                ConfigKey::INVOKABLES => $this->invokables,
                ConfigKey::AUTOWIRES => $this->autowires,
                ConfigKey::ALIASES => $this->aliases,
                ConfigKey::DELEGATORS => $this->delegators,
                ConfigKey::SERVICES => $this->services,
                PlanCompiler::CONFIG_KEY => $this->diPlans,
                PlanCompiler::FILE_CONFIG_KEY => $this->diPlansFile,
                PlanDispatcher::CONFIG_KEY => $this->diPlanDispatcherMap,
                ConfigKey::PARAMETER_RESOLVERS => $this->resolversToMap($this->parameterResolvers),
                ConfigKey::PROPERTY_RESOLVERS => $this->resolversToMap($this->propertyResolvers),
                ConfigKey::PARAMETER_RESOLVERS_REPLACE => $this->replaceParameterResolvers,
                ConfigKey::PROPERTY_RESOLVERS_REPLACE => $this->replacePropertyResolvers,
        ];

        return $data;
    }

    /**
     * Flattens a list of [resolver, priority] pairs into a priority-keyed map.
     *
     * On priority collision, the later entry wins - only relevant for
     * {@see toArray()} output (see its docblock).
     *
     * @param list<array{0: callable|string, 1: int}> $resolvers
     * @return array<int, callable|string>
     */
    private function resolversToMap(array $resolvers): array
    {
        $map = [];
        foreach ($resolvers as [$resolver, $priority]) {
            $map[$priority] = $resolver;
        }

        return $map;
    }

    /**
     * Creates the entry resolver chain. Override in a subclass to replace
     * the chain wholesale; for piecewise tweaks prefer the parameter / property
     * resolver slot API.
     */
    protected function createEntryResolver(
        ParametersResolver $parametersResolver,
        PropertiesResolver $propertiesResolver,
        ContainerInterface $container,
    ): EntryResolverInterface {
        return $this->createDefaultEntryResolver(
            $parametersResolver,
            $propertiesResolver,
            $container,
        );
    }

    /**
     * Creates default entry resolver chain.
     *
     * Resolution order:
     * 1. FactoryResolver - manual factories
     * 2. InvokableResolver - simple classes without dependencies
     * 3. ReflectionResolver - autowiring fallback
     */
    protected function createDefaultEntryResolver(
        ParametersResolver $parametersResolver,
        PropertiesResolver $propertiesResolver,
        ContainerInterface $container,
    ): EntryResolverInterface {
        $composite = new CompositeResolver();

        // One ProxyFactory shared by every entry resolver - keeps the
        // PHP 8.4 lazy-object machinery encapsulated and swappable from
        // the outside if needed (tests, alternate runtimes).
        $proxyFactory = new ProxyFactory();

        // Always register FactoryResolver and InvokableResolver - they own
        // the FactoryDefinition / ClassDefinition / InvokableDefinition
        // contracts that Container::set() relies on, so they must be present
        // even when the builder starts with empty factories/invokables.
        $composite->addResolver(new EntryFactoryResolver($this->factories, $container, $proxyFactory));
        $composite->addResolver(new InvokableResolver($this->invokables, $proxyFactory));

        $composite->addResolver(new ReflectionResolver(
            new InstanceCreator($parametersResolver),
            new PropertyInjector($propertiesResolver),
            new SetUpRunner(
                $parametersResolver,
                new ContainerValueUnwrapper(new ContainerValue($container, $this->config ?? new Config([]))),
                new EntryIdUnwrapper($container),
                new ConfigUnwrapper($container),
                new EnvUnwrapper($container),
            ),
            $proxyFactory,
        ));

        return $composite;
    }

    /**
     * Fills ParametersResolver with sub-resolvers.
     *
     * Order:
     * 1. Defaults from {@see buildDefaultParameterResolvers()} - skipped when
     *    {@see $replaceParameterResolvers} is true.
     * 2. User resolvers from {@see ConfigKey::PARAMETER_RESOLVERS}.
     */
    protected function fillParametersResolver(
        ParametersResolver $resolver,
        ContainerInterface $container,
    ): void {
        if (!$this->replaceParameterResolvers) {
            foreach ($this->buildDefaultParameterResolvers($container) as [$subResolver, $priority]) {
                $resolver->add($subResolver, $priority);
            }
        }

        foreach ($this->parameterResolvers as [$resolverConfig, $priority]) {
            $resolver->add($this->materializeResolver($resolverConfig, $container), $priority);
        }
    }

    /**
     * Fills PropertiesResolver with sub-resolvers.
     *
     * Mirrors {@see fillParametersResolver()} semantics for the property chain.
     */
    protected function fillPropertiesResolver(
        PropertiesResolver $resolver,
        ContainerInterface $container,
    ): void {
        if (!$this->replacePropertyResolvers) {
            foreach ($this->buildDefaultPropertyResolvers($container) as [$subResolver, $priority]) {
                $resolver->add($subResolver, $priority);
            }
        }

        foreach ($this->propertyResolvers as [$resolverConfig, $priority]) {
            $resolver->add($this->materializeResolver($resolverConfig, $container), $priority);
        }
    }

    /**
     * Produces the built-in parameter resolver chain as a slot-keyed map.
     * Subclasses override this to tweak the baseline chain wholesale.
     *
     * @return array<string, array{0: ParameterResolverInterface, 1: int}>
     */
    protected function buildDefaultParameterResolvers(ContainerInterface $container): array
    {
        $shared = $this->sharedResolvers($container);

        return [
            ParameterArrayResolver::class  => [new ParameterArrayResolver(),            self::PRIORITY_PARAM_ARRAY],
            ArrayTypedResolver::class      => [new ArrayTypedResolver(),                self::PRIORITY_PARAM_ARRAY_TYPED],
            MakeAttributeResolver::class   => [$shared[MakeAttributeResolver::class],   self::PRIORITY_PARAM_MAKE],
            EnvResolver::class             => [$shared[EnvResolver::class],             self::PRIORITY_PARAM_ENV],
            EntryIdResolver::class         => [$shared[EntryIdResolver::class],         self::PRIORITY_PARAM_ENTRY_ID],
            ConfigAttributeResolver::class => [$shared[ConfigAttributeResolver::class], self::PRIORITY_PARAM_CONFIG],
            AutowireByTypeResolver::class  => [new AutowireByTypeResolver($container),  self::PRIORITY_PARAM_AUTOWIRE],
            DefaultValueResolver::class    => [new DefaultValueResolver(),              self::PRIORITY_PARAM_DEFAULT_VALUE],
            NullableResolver::class        => [new NullableResolver(),                  self::PRIORITY_PARAM_NULLABLE],
        ];
    }

    /**
     * Produces the built-in property resolver chain as a slot-keyed map.
     *
     * NOTE: DefaultValueResolver and NullableResolver are deliberately NOT in
     * the property chain. They exist for parameters (a parameter MUST receive
     * a value); for properties, PHP already assigns declared defaults before
     * the constructor runs - injecting a default on top overwrites whatever
     * the constructor just set. Only resolvers driven by explicit intent
     * (attribute or context key) should produce values for properties.
     *
     * @return array<string, array{0: PropertyResolverInterface, 1: int}>
     */
    protected function buildDefaultPropertyResolvers(ContainerInterface $container): array
    {
        $shared = $this->sharedResolvers($container);

        return [
            PropertyArrayResolver::class   => [new PropertyArrayResolver(),             self::PRIORITY_PROP_ARRAY],
            InitResolver::class            => [new InitResolver($container->get(CallableInvokerInterface::class)), self::PRIORITY_PROP_INIT],
            MakeAttributeResolver::class   => [$shared[MakeAttributeResolver::class],   self::PRIORITY_PROP_MAKE],
            EnvResolver::class             => [$shared[EnvResolver::class],             self::PRIORITY_PROP_ENV],
            EntryIdResolver::class         => [$shared[EntryIdResolver::class],         self::PRIORITY_PROP_ENTRY_ID],
            InjectResolver::class          => [new InjectResolver($container),          self::PRIORITY_PROP_INJECT],
            ConfigAttributeResolver::class => [$shared[ConfigAttributeResolver::class], self::PRIORITY_PROP_CONFIG],
        ];
    }

    /**
     * Lazily creates resolvers that are stateless w.r.t. the chain they live
     * in and therefore safe to share between the parameter and property
     * defaults (MakeAttributeResolver, EnvResolver, EntryIdResolver,
     * ConfigAttributeResolver). Saves duplicate construction on every
     * container build.
     *
     * @return array<class-string, ParameterResolverInterface|PropertyResolverInterface>
     */
    private function sharedResolvers(ContainerInterface $container): array
    {
        $containerId = spl_object_id($container);

        if ($this->sharedResolvers !== null && $this->sharedResolversContainerId === $containerId) {
            return $this->sharedResolvers;
        }

        $factory = $container->get(FactoryInterface::class);
        $this->sharedResolversContainerId = $containerId;

        return $this->sharedResolvers = [
            MakeAttributeResolver::class   => new MakeAttributeResolver($factory),
            EnvResolver::class             => new EnvResolver($container),
            EntryIdResolver::class         => new EntryIdResolver($container),
            ConfigAttributeResolver::class => new ConfigAttributeResolver($container),
        ];
    }

    /**
     * Turns a slot or anonymous-registration specification into a concrete
     * resolver instance.
     *
     * Accepted forms:
     *  - {@see ParameterResolverInterface} / {@see PropertyResolverInterface}
     *    instance - used as-is (for pre-built resolver objects supplied via
     *    the slot API).
     *  - {@see Closure} - invoked with the container; expected to return the resolver.
     *  - Other callable (array `[class, method]`, function name, invokable
     *    object) - invoked with the container.
     *  - String that isn't directly callable - treated as a service id and
     *    looked up through `$container->get()`.
     *
     * @param callable|string|ParameterResolverInterface|PropertyResolverInterface $config
     */
    protected function materializeResolver(mixed $config, ContainerInterface $container): object
    {
        if ($config instanceof ParameterResolverInterface
            || $config instanceof PropertyResolverInterface
        ) {
            return $config;
        }

        if ($config instanceof Closure) {
            return $config($container);
        }

        if (is_callable($config)) {
            return $config($container);
        }

        if (is_string($config)) {
            return $container->get($config);
        }

        throw new \InvalidArgumentException(sprintf(
            'Resolver specification must be a resolver instance, Closure, callable, '
            . 'or service id string; got %s.',
            get_debug_type($config),
        ));
    }

    // =========================================================================
    // Fluent API
    // =========================================================================

    /**
     * @param callable(ContainerValue, array<string|int, mixed>):mixed $factory
     */
    public function addFactory(string $id, callable $factory): static
    {
        $this->factories[$id] = $factory;
        return $this;
    }

    /**
     * @param array<string, callable(ContainerValue, array<string|int, mixed>):mixed|string|array{0: string, 1: string}|\Componenta\DI\Definition\FactoryDefinition|\Componenta\DI\Definition\ClassDefinition> $factories
     */
    public function addFactories(array $factories): static
    {
        $this->factories = [...$this->factories, ...$factories];
        return $this;
    }

    public function addInvokable(string $classOrAlias, ?string $class = null): static
    {
        if ($class === null) {
            if (!in_array($classOrAlias, $this->invokables, true)) {
                $this->invokables[] = $classOrAlias;
            }
        } else {
            if (!in_array($class, $this->invokables, true)) {
                $this->invokables[] = $class;
            }
            // Explicit alias takes precedence - do not overwrite.
            if (!isset($this->aliases[$classOrAlias])) {
                $this->aliases[$classOrAlias] = $class;
            }
        }

        return $this;
    }

    public function addInvokables(array $invokables): static
    {
        foreach ($invokables as $key => $value) {
            is_int($key)
                ? $this->addInvokable($value)
                : $this->addInvokable($key, $value);
        }
        return $this;
    }

    public function addAutowire(string $class): static
    {
        if (!in_array($class, $this->autowires, true)) {
            $this->autowires[] = $class;
        }

        return $this;
    }

    public function addAutowires(array $classes): static
    {
        foreach ($classes as $class) {
            $this->addAutowire($class);
        }

        return $this;
    }

    public function addAlias(string $alias, string $target): static
    {
        $this->aliases[$alias] = $target;
        return $this;
    }

    public function addAliases(array $aliases): static
    {
        $this->aliases = [...$this->aliases, ...$aliases];
        return $this;
    }

    public function addDelegator(string $id, callable|string|array $delegator): static
    {
        $this->delegators[$id][] = $delegator;
        return $this;
    }

    public function addDelegators(array $delegators): static
    {
        foreach ($delegators as $id => $delegatorList) {
            $list = is_array($delegatorList) && array_is_list($delegatorList)
                ? $delegatorList
                : [$delegatorList];

            foreach ($list as $delegator) {
                $this->delegators[$id][] = $delegator;
            }
        }

        return $this;
    }

    public function addService(string $id, mixed $service): static
    {
        $this->services[$id] = $service;
        return $this;
    }

    public function addServices(array $services): static
    {
        $this->services = [...$this->services, ...$services];
        return $this;
    }

}
