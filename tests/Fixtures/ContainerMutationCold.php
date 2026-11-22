<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ContainerMutationCold;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableResolver;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\ContainerConstruction;
use Componenta\DI\Internal\EntryCache;
use Componenta\DI\Internal\ExternalContainerRegistry;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use Psr\Container\ContainerInterface;
use ReflectionClass;

if (!isset($argv[1], $argv[2], $argv[3])) {
    throw new LogicException('Expected the autoload path, source path and mutation scenario.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
$loader = SubprocessAutoload::load($argv[1]);
$source = realpath($argv[2]);
$containerFile = new ReflectionClass(Container::class)->getFileName();
if ($source === false || $containerFile === false || realpath($containerFile) !== $source . DIRECTORY_SEPARATOR . 'Container.php') {
    throw new LogicException('The cold mutation fixture must load the selected checkout source.');
}
$scenario = $argv[3];
$helper = match ($scenario) {
    'delegator', 'alias', 'set', 'external retirement' => MutationRetirement::class,
    'external registry', 'nested registry' => ExternalContainerRegistry::class,
    default => throw new LogicException('Unknown cold mutation scenario.'),
};

final readonly class Decorator
{
    public function __construct(public string $label) {}

    public function __invoke(string $entry): string
    {
        return $entry . ':' . $this->label;
    }
}

final readonly class Provider implements ContainerInterface
{
    public function __construct(private Decorator $decorator) {}

    public function has(string $id): bool
    {
        return $id === 'owner';
    }

    public function get(string $id): Decorator
    {
        if (!$this->has($id)) {
            throw new LogicException('Unexpected external lookup.');
        }
        return $this->decorator;
    }
}

final class Executor implements CallableExecutorInterface
{
    public ?CallableResolverInterface $resolver = null;

    public function resolve(mixed $callable): callable
    {
        return ($this->resolver ?? throw new LogicException('The fixture executor must be ready.'))->resolve($callable);
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return ($this->resolve($callable))(...$params);
    }
}

$resolver = new class () implements EntryResolverInterface {
    public function can(string $id): bool
    {
        return false;
    }

    public function resolve(string $id, EntryParameters $params): mixed
    {
        throw new LogicException('The cached service must not reach the resolver.');
    }
};
$executor = new Executor();
$container = ContainerConstruction::create(
    $resolver,
    new AliasResolver(['public' => 'ordinary', 'selected' => 'owner']),
    $executor,
    cache: new EntryCache([
        'ordinary' => 'ordinary',
        'owner' => new Decorator('old'),
        'next.owner' => new Decorator('next'),
        'product' => 'base',
    ]),
);
$executor->resolver = new CallableResolver($container);
if (class_exists($helper, false)) {
    throw new LogicException('The mutation helper must not already be loaded.');
}

$autoloaded = false;
$events = [];
$decoratorCalls = 0;
$observer = static function (string $class) use ($helper, $loader, $container, $scenario, &$autoloaded, &$events): void {
    if ($class !== $helper) {
        return;
    }
    $loader->loadClass($class);
    $autoloaded = true;
    if ($scenario === 'delegator') {
        $container->alias('public', ContainerInterface::class);
        return;
    }
    if ($scenario === 'alias') {
        $container->delegator('public', static fn(mixed $entry): string => is_string($entry) ? $entry . ':nested' : 'decorated-core');
        return;
    }
    if ($scenario === 'set') {
        $container->alias('selected', 'next.owner');
    }
    $container->delegator('product', 'owner');
    $events[] = $container->get('product');
    if ($scenario === 'nested registry') {
        $container->addContainer(new Provider(new Decorator('nested')));
        $events[] = $container->get('product');
    }
};
spl_autoload_register($observer, true, true);
$error = null;
try {
    match ($scenario) {
        'delegator' => $container->delegator('public', static function () use (&$decoratorCalls): string {
            ++$decoratorCalls;
            return 'decorated-core';
        }),
        'alias' => $container->alias('public', ContainerInterface::class),
        'set' => $container->set('selected', new Decorator('new')),
        default => $container->addContainer(new Provider(new Decorator('new'))),
    };
} catch (InvalidConfigurationException $failure) {
    $error = $failure::class;
} finally {
    spl_autoload_unregister($observer);
}

$label = static function (string $id) use ($container): string {
    $owner = $container->get($id);
    if (!$owner instanceof Decorator) {
        throw new LogicException('Expected a decorator service.');
    }
    return $owner->label;
};
$result = match ($scenario) {
    'delegator' => [
        'public_is_container' => $container->get('public') === $container,
        'decorator_calls' => $decoratorCalls,
    ],
    'alias' => [
        'public' => $container->get('public'),
        'protected_is_container' => $container->get(ContainerInterface::class) === $container,
    ],
    'set' => [
        'owner' => $label('owner'),
        'selected' => $label('selected'),
        'product' => $container->get('product'),
    ],
    default => [
        'owner' => $label('owner'),
        'product' => $container->get('product'),
    ],
};
echo json_encode([
    'autoloaded' => $autoloaded,
    'error' => $error,
    'events' => $events,
    'result' => $result,
], JSON_THROW_ON_ERROR);
