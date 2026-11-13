<?php

declare(strict_types=1);

namespace Componenta\DI\Audit\DefinitionLifecycle;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;
use stdClass;
use WeakReference;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** Diagnostic observations, not assertions that the current defect is desired. */
final readonly class Product
{
    public function __construct(public string $source) {}
}

final readonly class CapturingFactory
{
    public function __construct(public object $payload) {}

    public function __invoke(): Product
    {
        return new Product('old');
    }
}

final class ThrowingFactory
{
    public function __invoke(): Product
    {
        return new Product('old');
    }

    public function __destruct()
    {
        throw new LogicException('retired factory destructor failed');
    }
}

function container(callable $factory): Container
{
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([ConfigKey::FACTORIES => ['product' => $factory]]),
    )->container;
    if (!$container instanceof Container) {
        throw new LogicException('Expected the Componenta container.');
    }
    return $container;
}

/** @return array{Container, list<WeakReference<object>>} */
function replaceCapturingFactory(bool $sameOwner): array
{
    $payload = new stdClass();
    $factory = new CapturingFactory($payload);
    $references = [WeakReference::create($factory), WeakReference::create($payload)];
    $container = container($factory);
    $container->get('product');
    $container->set('product', $sameOwner
        ? Definition::factory(static fn(): object => new stdClass())
        : Definition::invokable(stdClass::class));
    return [$container, $references];
}

$observations = ['php' => PHP_VERSION];
foreach ([false, true] as $sameOwner) {
    [$container, $references] = replaceCapturingFactory($sameOwner);
    gc_collect_cycles();
    $label = $sameOwner ? 'same_owner' : 'different_owner';
    $observations[$label] = [
        'factory_and_capture_retained' => array_map(
            static fn(WeakReference $reference): bool => $reference->get() !== null,
            $references,
        ),
        'new_make_class' => $container->make('product')::class,
    ];
    unset($container);
    gc_collect_cycles();
    $observations[$label]['retained_after_container_disposal'] = array_map(
        static fn(WeakReference $reference): bool => $reference->get() !== null,
        $references,
    );
}

$container = container(new ThrowingFactory());
$old = $container->get('product');
$failure = null;
try {
    $container->set('product', Definition::factory(static fn(): Product => new Product('new')));
} catch (InvalidConfigurationException $exception) {
    $failure = [$exception::class, $exception->getPrevious()?->getMessage()];
}
$observations['throwing_retirement'] = [
    'failure' => $failure,
    'get' => $container->get('product')->source,
    'make' => $container->make('product')->source,
    'shared_is_old' => $container->get('product') === $old,
];

echo json_encode($observations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
