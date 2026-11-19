<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ContainerConstructionRetry;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\DelegatorRegistry;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;

if (!isset($argv[1], $argv[2], $argv[3])) {
    throw new LogicException('Expected the autoload path, source path and collaborator.');
}
require $argv[1];
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
    default => throw new LogicException('Unknown bootstrap collaborator.'),
};
if (class_exists($target, false)) {
    throw new LogicException('The selected bootstrap collaborator must not already be loaded.');
}

$resolver = new CompositeResolver(new class () implements EntryResolverInterface {
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
});
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
$loader = static function (string $class) use ($target, $failure, &$autoloadObserved): void {
    if ($class === $target) {
        $autoloadObserved = true;
        throw $failure;
    }
};
spl_autoload_register($loader, true, true);
$caught = null;
try {
    new Container($resolver, $aliases, $executor);
} catch (Throwable $error) {
    $caught = $error;
} finally {
    spl_autoload_unregister($loader);
}

$retryError = null;
$result = null;
try {
    $container = new Container($resolver, $aliases, $executor);
    $result = [
        'value' => $container->get('application.alias'),
        'self' => $container->get(ContainerInterface::class) === $container,
        'call' => $container->call(static fn(string $value): string => $value, ['value' => 'invoked']),
    ];
} catch (Throwable $error) {
    $retryError = ['class' => $error::class, 'message' => $error->getMessage()];
}

echo json_encode([
    'autoload_observed' => $autoloadObserved,
    'original_failure_preserved' => $caught === $failure,
    'retry_error' => $retryError,
    'result' => $result,
], JSON_THROW_ON_ERROR);
