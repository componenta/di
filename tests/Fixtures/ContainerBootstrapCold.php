<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ContainerBootstrapCold;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Throwable;

if (!isset($argv[1], $argv[2])) {
    throw new LogicException('Expected the autoload and source paths.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
SubprocessAutoload::load($argv[1]);
$source = realpath($argv[2]);
$containerFile = new ReflectionClass(Container::class)->getFileName();
if ($source === false || $containerFile === false || realpath($containerFile) !== $source . DIRECTORY_SEPARATOR . 'Container.php') {
    throw new LogicException('The cold bootstrap fixture must load the selected checkout source.');
}
if (class_exists(CycleGuard::class, false) || class_exists(ProxyFactory::class, false)) {
    throw new LogicException('The bootstrap collaborators must not already be loaded.');
}

final class Product
{
    public string $value = 'ordinary';
}

$cache = new EntryCache(['app' => 'ready']);
$errors = [];
$autoloadObserved = false;
$initialize = static function (Product $product): void {
    $product->value = 'initialized';
};
$observer = static function (string $class) use ($cache, &$errors, &$autoloadObserved, $initialize): void {
    if ($class !== CycleGuard::class && $class !== ProxyFactory::class) {
        return;
    }
    $autoloadObserved = true;
    if (!$cache->tryGetBase(ContainerInterface::class, $container) || !$container instanceof Container) {
        return;
    }

    try {
        if ($container->get('app') !== 'ready') {
            throw new LogicException('The published service must be ready.');
        }
        $lazy = $container->makeLazy(Product::class, $initialize);
        if ($lazy->value !== 'initialized') {
            throw new LogicException('The published lazy factory must initialize its product.');
        }
    } catch (Throwable $error) {
        $errors[$class] = $error->getPrevious()?->getMessage() ?? $error->getMessage();
    }
};
spl_autoload_register($observer, true, true);

$resolver = new class () implements EntryResolverInterface {
    public function can(string $id): bool
    {
        return false;
    }

    public function resolve(string $id, array $params = []): mixed
    {
        throw new LogicException('The cached service must not reach the resolver.');
    }
};
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

try {
    $container = new Container($resolver, new AliasResolver(), $executor, cache: $cache);
} finally {
    spl_autoload_unregister($observer);
}
$lazy = $container->makeLazy(Product::class, $initialize);

echo json_encode([
    'autoload_observed' => $autoloadObserved,
    'early_errors' => $errors,
    'value' => $container->get('app'),
    'lazy_value' => $lazy->value,
], JSON_THROW_ON_ERROR);
