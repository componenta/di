<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\App\Discovery\ListenerRestorer;
use Componenta\CQRS\Command\CommandBusInterface;
use Componenta\CQRS\Query\QueryBusInterface;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\Http\Router\Router;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Warmup(2)]
final class ContainerBench
{
    private static bool $initialized = false;
    private static ?string $cacheFile = null;
    private static ?string $containerCacheFile = null;
    private static ?string $containerCacheDir = null;
    private static Config $config;
    private static array $containerCache = [];
    private static bool $hasConfigDependencies = false;
    private static Container $warmContainer;
    private static bool $hasRouter = false;
    private static bool $hasCommandBus = false;
    private static bool $hasQueryBus = false;
    private static Closure $routerCallable;

    /** @var list<class-string> */
    private static array $classes = [];

    public function setUp(): void
    {
        if (self::$initialized) {
            return;
        }

        $root = dirname(__DIR__, 3);
        $cacheFile = $root . '/var/cache/config.cache.php';
        $containerCacheFile = $root . '/var/cache/container.cache.php';

        if (is_file($cacheFile)) {
            self::$cacheFile = $cacheFile;
            self::$config = ConfigLoader::loadFromFile($cacheFile);

            $configData = self::$config->toArray();
            self::$hasConfigDependencies = isset($configData['dependencies']) && is_array($configData['dependencies']);
            self::$classes = self::discoveredClasses($configData, dirname($cacheFile));

            if (self::$hasConfigDependencies) {
                self::$containerCache = [
                    'version' => ContainerBuilder::CACHE_VERSION,
                    'dependencies' => ContainerBuilder::normalizeDependencies($configData['dependencies']),
                ];
            }
        } else {
            self::$config = new Config([]);
        }

        if (is_file($containerCacheFile)) {
            self::$containerCacheFile = $containerCacheFile;
            self::$containerCacheDir = dirname($containerCacheFile);
            $containerCache = require $containerCacheFile;
            if (is_array($containerCache)) {
                self::$containerCache = $containerCache;
            }
        }

        self::$warmContainer = self::buildContainer();
        self::$hasRouter = self::$warmContainer->has(Router::class);
        self::$hasCommandBus = self::$warmContainer->has(CommandBusInterface::class);
        self::$hasQueryBus = self::$warmContainer->has(QueryBusInterface::class);
        self::$routerCallable = static fn (Router $router): Router => $router;

        if (self::$hasRouter) {
            self::$warmContainer->get(Router::class);
        }

        if (self::$hasCommandBus) {
            self::$warmContainer->get(CommandBusInterface::class);
        }

        if (self::$hasQueryBus) {
            self::$warmContainer->get(QueryBusInterface::class);
        }

        self::$initialized = true;
    }

    private static function buildContainer(): Container
    {
        if (self::$hasConfigDependencies || self::$containerCache === []) {
            return ContainerBuilder::configure(self::$config)->build();
        }

        return ContainerBuilder::configureFromCache(
            self::$config,
            self::$containerCache,
            self::$containerCacheDir,
        )->build();
    }

    /**
     * @return list<class-string>
     */
    private static function discoveredClasses(array $configData, string $cacheDir): array
    {
        $classes = $configData[ListenerRestorer::CACHE_KEY]['classes'] ?? null;

        if (is_array($classes) && $classes !== []) {
            return array_values($classes);
        }

        $file = $configData[ListenerRestorer::CACHE_FILE_KEY] ?? null;

        if (!is_string($file) || $file === '') {
            return [];
        }

        $path = self::isAbsolutePath($file) ? $file : $cacheDir . '/' . $file;

        if (!is_file($path)) {
            return [];
        }

        $payload = require $path;

        if (!is_array($payload) || ($payload['version'] ?? null) !== ListenerRestorer::CACHE_VERSION) {
            return [];
        }

        $classes = $payload['cache']['classes'] ?? [];

        return is_array($classes) ? array_values($classes) : [];
    }

    private static function isAbsolutePath(string $path): bool
    {
        return $path[0] === '/'
            || $path[0] === '\\'
            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':');
    }

    #[Revs(20)]
    #[Groups(['container', 'config', 'load'])]
    public function benchLoadCachedConfig(): void
    {
        if (self::$cacheFile === null) {
            return;
        }

        ConfigLoader::loadFromFile(self::$cacheFile);
    }

    #[Revs(100)]
    #[Groups(['container', 'cache', 'load'])]
    public function benchLoadContainerCache(): void
    {
        if (self::$containerCacheFile === null) {
            return;
        }

        require self::$containerCacheFile;
    }

    #[Revs(100)]
    #[Groups(['container', 'builder', 'configure'])]
    public function benchConfigureBuilder(): void
    {
        ContainerBuilder::configure(self::$config);
    }

    #[Revs(100)]
    #[Groups(['container', 'builder', 'build'])]
    public function benchBuildContainer(): void
    {
        if (!self::$hasConfigDependencies) {
            return;
        }

        ContainerBuilder::configure(self::$config)->build();
    }

    #[Revs(100)]
    #[Groups(['container', 'builder', 'build', 'cache'])]
    public function benchBuildContainerFromCache(): void
    {
        if (self::$containerCache === []) {
            return;
        }

        ContainerBuilder::configureFromCache(
            self::$config,
            self::$containerCache,
            self::$containerCacheDir,
        )->build();
    }

    #[Revs(20)]
    #[Groups(['container', 'bootstrap', 'cache'])]
    public function benchLoadConfigAndBuildContainerFromCache(): void
    {
        if (self::$cacheFile === null || self::$containerCacheFile === null) {
            return;
        }

        $config = ConfigLoader::loadFromFile(self::$cacheFile);
        $containerCache = require self::$containerCacheFile;

        if (!is_array($containerCache)) {
            return;
        }

        ContainerBuilder::configureFromCache(
            $config,
            $containerCache,
            dirname(self::$containerCacheFile),
        )->build();
    }

    #[Revs(50)]
    #[Groups(['container', 'get', 'router'])]
    public function benchBuildAndFirstGetRouter(): void
    {
        $container = self::$hasConfigDependencies
            ? ContainerBuilder::configure(self::$config)->build()
            : self::buildContainer();

        if (self::$hasRouter) {
            $container->get(Router::class);
        }
    }

    #[Revs(50)]
    #[Groups(['container', 'get', 'cqrs'])]
    public function benchBuildAndFirstGetCommandBus(): void
    {
        $container = self::$hasConfigDependencies
            ? ContainerBuilder::configure(self::$config)->build()
            : self::buildContainer();

        if (self::$hasCommandBus) {
            $container->get(CommandBusInterface::class);
        }
    }

    #[Revs(50)]
    #[Groups(['container', 'get', 'cqrs'])]
    public function benchBuildAndFirstGetQueryBus(): void
    {
        $container = self::$hasConfigDependencies
            ? ContainerBuilder::configure(self::$config)->build()
            : self::buildContainer();

        if (self::$hasQueryBus) {
            $container->get(QueryBusInterface::class);
        }
    }

    #[Revs(1000)]
    #[Groups(['container', 'get', 'warm'])]
    public function benchWarmedGetRouter(): void
    {
        if (!self::$hasRouter) {
            return;
        }

        self::$warmContainer->get(Router::class);
    }

    #[Revs(1000)]
    #[Groups(['container', 'get', 'warm', 'cqrs'])]
    public function benchWarmedGetCommandBus(): void
    {
        if (!self::$hasCommandBus) {
            return;
        }

        self::$warmContainer->get(CommandBusInterface::class);
    }

    #[Revs(1000)]
    #[Groups(['container', 'get', 'warm', 'cqrs'])]
    public function benchWarmedGetQueryBus(): void
    {
        if (!self::$hasQueryBus) {
            return;
        }

        self::$warmContainer->get(QueryBusInterface::class);
    }

    #[Revs(1000)]
    #[Groups(['container', 'call'])]
    public function benchCallRouterCallable(): void
    {
        if (!self::$hasRouter) {
            return;
        }

        self::$warmContainer->call(self::$routerCallable);
    }

    #[Revs(5)]
    #[Groups(['container', 'plans', 'sparse'])]
    public function benchCompilePlansSparse(): void
    {
        if (self::$classes === []) {
            return;
        }

        ContainerBuilder::configure(self::$config)
            ->compilePlans(self::$classes, PlanCompiler::MODE_SPARSE);
    }

    #[Revs(5)]
    #[Groups(['container', 'plans', 'complete'])]
    public function benchCompilePlansComplete(): void
    {
        if (self::$classes === []) {
            return;
        }

        ContainerBuilder::configure(self::$config)
            ->compilePlans(self::$classes, PlanCompiler::MODE_COMPLETE);
    }
}
