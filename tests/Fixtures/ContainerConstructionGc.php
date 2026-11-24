<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ContainerConstructionGc;

use Closure;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerConstruction;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\ExternalContainerRegistry;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use stdClass;

final class CyclicSeed
{
    private readonly self $cycle;

    /** @param Closure():void $onRelease */
    public function __construct(private readonly Closure $onRelease)
    {
        $this->cycle = $this;
    }

    public function __destruct()
    {
        ($this->cycle->onRelease)();
    }
}

/** @return list<stdClass> */
function holdBufferedRoots(int $slack): array
{
    $status = gc_status();
    $roots = [];
    $targetRoots = $status['threshold'] - $status['roots'] - $slack;
    for ($index = 0; $index < $targetRoots; ++$index) {
        // Keep the objects live while adding them to the GC root buffer.
        $rootCandidate = new stdClass();
        $rootCandidate->live = true;
        $roots[] = $rootCandidate;
        unset($rootCandidate);
    }
    return $roots;
}

/** @return bool|null Whether the construction invariant held, or null without a reentry. */
function tryWithGcPressure(CallableExecutorInterface $executor, string $shared, int $slack): ?bool
{
    $root = new CompositeResolver();
    $aliases = new AliasResolver();
    $cache = new EntryCache();
    $nestedRoot = $shared === 'root' ? $root : new CompositeResolver();
    $nestedCache = $shared === 'cache' ? $cache : new EntryCache();
    $nestedAliases = $shared === 'aliases' ? $aliases : new AliasResolver();
    $delegators = new DelegatorRegistry($executor);
    $external = new ExternalContainerRegistry();
    $guard = new CycleGuard();
    $proxy = new ProxyFactory();
    $outer = null;
    $aliasWritten = false;
    $state = new class () {
        public bool $running = false;
        public bool $reentered = false;
        public ?Container $nested = null;
    };

    gc_collect_cycles();
    $seed = new CyclicSeed(static function () use (
        $state,
        $nestedRoot,
        $nestedCache,
        $nestedAliases,
        $executor,
        $proxy,
    ): void {
        if ($state->running) {
            $state->reentered = true;
            try {
                $state->nested = ContainerConstruction::create($nestedRoot, $nestedAliases, $executor, $nestedCache, proxyFactory: $proxy);
            } catch (InvalidConfigurationException) {
                // The outer operation already owns the shared object or changed its routes.
            }
        }
    });
    unset($seed);

    $roots = holdBufferedRoots($slack);
    $state->running = true;
    try {
        if ($shared === 'aliases') {
            $aliases->set(ContainerInterface::class, 'application');
            $aliasWritten = true;
        } else {
            $outer = ContainerConstruction::create($root, $aliases, $executor, $cache, $delegators, $external, $guard, $proxy);
        }
    } catch (InvalidConfigurationException) {
        // The nested construction took ownership first.
    } finally {
        $state->running = false;
    }
    unset($roots);

    if (!$state->reentered) {
        return null;
    }
    $nested = $state->nested;
    $owner = $outer ?? $nested;
    $aliasMatches = $shared !== 'aliases'
        || $aliases->target(ContainerInterface::class) === ($aliasWritten ? 'application' : null);
    return ($outer !== null || $aliasWritten) !== ($nested !== null)
        && $aliasMatches
        && $owner?->get(ContainerInterface::class) === $owner;
}

function tryAliasMutationWithGcPressure(CallableExecutorInterface $executor, int $slack): ?bool
{
    $container = ContainerConstruction::create(
        new CompositeResolver(),
        new AliasResolver(),
        $executor,
        cache: new EntryCache(['first' => 'first service', 'second' => 'second service']),
    );
    $container->alias('preloaded', 'first');
    $container->get('first');
    $container->get('second');
    $state = new class () {
        public bool $running = false;
        public bool $reentered = false;
        public bool $changed = false;
    };
    gc_collect_cycles();
    $seed = new CyclicSeed(static function () use ($container, $state): void {
        if ($state->running) {
            $state->reentered = true;
            try {
                $container->alias('second', 'first');
                $state->changed = true;
            } catch (CircularDependencyException) {
                // The outer mutation already published first -> second.
            }
        }
    });
    unset($seed);

    $roots = holdBufferedRoots($slack);
    $state->running = true;
    $changed = false;
    try {
        $container->alias('first', 'second');
        $changed = true;
    } catch (CircularDependencyException) {
        // The nested mutation won with second -> first.
    } finally {
        $state->running = false;
    }
    unset($roots);

    if (!$state->reentered) {
        return null;
    }
    $expected = $changed ? 'second service' : 'first service';
    return $changed !== $state->changed
        && $container->get('first') === $expected
        && $container->get('second') === $expected;
}

if (!isset($argv[1], $argv[2], $argv[3])) {
    throw new LogicException('Expected the autoload path, source path and shared collaborator.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
SubprocessAutoload::load($argv[1]);
$containerFile = new ReflectionClass(Container::class)->getFileName();
if ($containerFile === false || realpath($containerFile) !== realpath($argv[2]) . DIRECTORY_SEPARATOR . 'Container.php') {
    throw new LogicException('The GC fixture must load the selected checkout source.');
}

$executor = new class () implements CallableExecutorInterface {
    public function resolve(mixed $callable): callable
    {
        if (!is_callable($callable)) {
            throw new LogicException('Expected a native callable.');
        }
        return $callable;
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return ($this->resolve($callable))(...$params);
    }
};
// Preload the normal collaborators so the reentry is caused by GC.
ContainerConstruction::create(new CompositeResolver(), new AliasResolver(), $executor, new EntryCache(), new DelegatorRegistry($executor));
class_exists(InvalidConfigurationException::class);
class_exists(CircularDependencyException::class);

$reentries = 0;
$violations = [];
for ($slack = 1; $slack <= 18; ++$slack) {
    $result = $argv[3] === 'routes'
        ? tryAliasMutationWithGcPressure($executor, $slack)
        : tryWithGcPressure($executor, $argv[3], $slack);
    if ($result !== null) {
        ++$reentries;
        if (!$result) {
            $violations[] = $slack;
        }
    }
}

echo json_encode(['reentries' => $reentries, 'violations' => $violations], JSON_THROW_ON_ERROR);
