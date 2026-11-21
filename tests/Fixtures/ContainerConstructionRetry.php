<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ContainerConstructionRetry;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\Resolver\Entry\DefinitionWriter;
use Componenta\DI\Internal\RuntimeCollaboratorOwnership;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;

if (!isset($argv[1], $argv[2], $argv[3])) {
    throw new LogicException('Expected the autoload path, source path and collaborator.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
$autoload = SubprocessAutoload::load($argv[1]);
$source = realpath($argv[2]);
$containerFile = new ReflectionClass(Container::class)->getFileName();
if ($source === false || $containerFile === false || realpath($containerFile) !== $source . DIRECTORY_SEPARATOR . 'Container.php') {
    throw new LogicException('The construction retry fixture must load the selected checkout source.');
}
$target = match ($argv[3]) {
    'cache' => EntryCache::class,
    'delegators' => DelegatorRegistry::class,
    'cycles' => CycleGuard::class,
    'proxy' => ProxyFactory::class,
    'writer', 'writer-reentry', 'cache-reentry', 'writer-core-alias', 'writer-application-alias' => DefinitionWriter::class,
    'ownership' => RuntimeCollaboratorOwnership::class,
    default => throw new LogicException('Unknown bootstrap collaborator.'),
};
if (class_exists($target, false)) {
    throw new LogicException('The selected bootstrap collaborator must not already be loaded.');
}

$fallback = new class () implements EntryResolverInterface {
    public function can(string $id): bool
    {
        return $id === 'application';
    }

    public function resolve(string $id, array $params = []): mixed
    {
        if (!$this->can($id)) {
            throw new LogicException('Unexpected fallback lookup.');
        }
        return 'ready';
    }
};
$resolver = new CompositeResolver($fallback);
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
$aliases = new AliasResolver(['application.alias' => 'application']);
$failure = new RuntimeException('Transient bootstrap autoload failure.');
$autoloadObserved = false;
$nested = new class () {
    public ?Container $container = null;
};
$reenter = str_ends_with($argv[3], '-reentry');
$lateAlias = match ($argv[3]) {
    'writer-core-alias' => ContainerInterface::class,
    'writer-application-alias' => 'late.application',
    default => null,
};
$sharedCache = $argv[3] === 'cache-reentry' || $lateAlias !== null ? new EntryCache() : null;
$loader = static function (string $class) use (
    $target,
    $failure,
    &$autoloadObserved,
    $reenter,
    $autoload,
    $resolver,
    $aliases,
    $executor,
    $nested,
    $sharedCache,
    $fallback,
    $lateAlias,
): void {
    if ($class !== $target) {
        return;
    }
    $autoloadObserved = true;
    if ($lateAlias !== null) {
        $autoload->loadClass($class);
        $aliases->set($lateAlias, 'application');
        return;
    }
    if (!$reenter) {
        throw $failure;
    }
    $autoload->loadClass($class);
    $nested->container = $sharedCache === null
        ? new Container($resolver, $aliases, $executor)
        : new Container(new CompositeResolver($fallback), clone $aliases, $executor, cache: $sharedCache);
};
spl_autoload_register($loader, true, true);
$caught = null;
$container = null;
try {
    $container = new Container($resolver, $aliases, $executor, cache: $sharedCache);
} catch (Throwable $error) {
    $caught = $error;
} finally {
    spl_autoload_unregister($loader);
}

$unexpectedCorePublication = $lateAlias === ContainerInterface::class
    && $sharedCache?->tryGetBase(ContainerInterface::class, $published);
$retryAliases = $lateAlias === ContainerInterface::class
    ? new AliasResolver(['application.alias' => 'application'])
    : $aliases;
$retryCache = $lateAlias === ContainerInterface::class ? $sharedCache : null;
$retryError = null;
$result = null;
try {
    $container ??= ($sharedCache === null ? $nested->container : null)
        ?? new Container($resolver, $retryAliases, $executor, cache: $retryCache);
    $result = [
        'value' => $container->get($lateAlias === 'late.application' ? $lateAlias : 'application.alias'),
        'self' => $container->get(ContainerInterface::class) === $container,
        'call' => $container->call(static fn(string $value): string => $value, ['value' => 'invoked']),
    ];
} catch (Throwable $error) {
    $retryError = ['class' => $error::class, 'message' => $error->getMessage()];
}

echo json_encode([
    'autoload_observed' => $autoloadObserved,
    'original_failure_preserved' => $caught === $failure,
    'duplicate_attachment_rejected' => $caught instanceof InvalidConfigurationException
        && $caught->getMessage() === 'A composite can be attached to only one container.',
    'shared_collaborator_rejected' => $caught instanceof InvalidConfigurationException
        && str_contains($caught->getMessage(), EntryCache::class),
    'protected_alias_rejected' => $caught instanceof InvalidConfigurationException
        && $caught->getMessage() === sprintf('Cannot replace protected DI alias "%s".', ContainerInterface::class),
    'unexpected_core_publication' => $unexpectedCorePublication,
    'nested_owner_preserved' => $nested->container === null || $nested->container->get(ContainerInterface::class) === $nested->container,
    'retry_error' => $retryError,
    'result' => $result,
], JSON_THROW_ON_ERROR);
