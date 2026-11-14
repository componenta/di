<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DefinitionStrategyDirectUse;

use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use WeakReference;

final readonly class Product
{
    public function __construct(public string $label = 'default') {}
}

final class OtherProduct {}

final readonly class CapturingFactory
{
    public function __construct(private object $payload) {}

    public function __invoke(): object
    {
        return $this->payload;
    }
}

test('direct factory strategy callers retain independent validated descriptors for the same id', function (): void {
    $container = (new ContainerBuilder())->build();
    $strategy = new FactoryResolver($container, new ProxyFactory());
    $first = $strategy->validateDefinition('entry', new FactoryDefinition(
        static function (ContainerValue $container, array $params): Product {
            $label = $params['label'] ?? null;
            if (!is_string($label)) {
                throw new \LogicException('Expected a string runtime label.');
            }
            return new Product('first:' . $label);
        },
    ));
    $second = $strategy->validateDefinition('entry', ClassDefinition::create(Product::class)->constructor(['label' => 'second']));

    expect($strategy->resolveDefinition('entry', $first, ['label' => 'one']))->toEqual(new Product('first:one'))
        ->and($strategy->resolveDefinition('entry', $second))->toEqual(new Product('second'))
        ->and($strategy->resolveDefinition('entry', $first, ['label' => 'two']))->toEqual(new Product('first:two'))
        ->and($strategy->supportsDefinition($first))->toBeFalse()
        ->and($strategy->supportsDefinition(new ReferenceDefinition('other')))->toBeFalse();
});

test('factory strategies do not retain a descriptor or its captured payload after its caller releases it', function (): void {
    $strategy = new FactoryResolver((new ContainerBuilder())->build(), new ProxyFactory());
    $payload = new \stdClass();
    $reference = WeakReference::create($payload);
    $factory = new CapturingFactory($payload);
    $prepared = $strategy->validateDefinition('entry', new FactoryDefinition($factory));
    unset($factory, $payload);

    expect($reference->get())->not->toBeNull();
    unset($prepared);

    expect($reference->get())->toBeNull()
        ->and($strategy->supportsDefinition(new FactoryDefinition(static fn(): object => new \stdClass())))->toBeTrue();
});

test('direct invokable strategy callers validate and execute independent class descriptors', function (): void {
    $strategy = new InvokableResolver();
    $first = $strategy->validateDefinition('entry', new InvokableDefinition(Product::class));
    $second = $strategy->validateDefinition('entry', new InvokableDefinition(OtherProduct::class));
    $one = $strategy->resolveDefinition('entry', $first, ['label' => 'ignored']);
    $two = $strategy->resolveDefinition('entry', $first);

    expect($one)->toEqual(new Product())
        ->and($two)->toEqual(new Product())
        ->and($two)->not->toBe($one)
        ->and($strategy->resolveDefinition('entry', $second))->toBeInstanceOf(OtherProduct::class)
        ->and($strategy->supportsDefinition(new ReferenceDefinition('other')))->toBeFalse()
        ->and(fn() => $strategy->validateDefinition('entry', ClassDefinition::create(Product::class)))
        ->toThrow(InvalidConfigurationException::class);
});

test('bootstrap and runtime invokable definitions share admission without becoming autowired construction', function (): void {
    $container = (new ContainerBuilder())
        ->addDefinition('entry', new InvokableDefinition(Product::class))
        ->build();

    expect($container->get('entry'))->toEqual(new Product())
        ->and($container->make('entry', ['label' => 'ignored']))->toEqual(new Product());

    $container->set('entry', ClassDefinition::create(Product::class)->constructor(['label' => 'configured']));
    expect($container->get('entry'))->toEqual(new Product('configured'));
    $container->set('entry', new InvokableDefinition(Product::class));
    expect($container->get('entry'))->toEqual(new Product());
});

test('admission keeps deferred factory owners unresolved until their product is requested', function (bool $method): void {
    $ownerCalls = 0;
    $container = (new ContainerBuilder())
        ->addFactory('owner', static function () use (&$ownerCalls): CapturingFactory {
            ++$ownerCalls;
            return new CapturingFactory(new Product('deferred'));
        })
        ->addFactory('entry', $method ? ['owner', '__invoke'] : 'owner')
        ->build();

    expect($ownerCalls)->toBe(0)
        ->and($container->has('entry'))->toBeTrue()
        ->and($ownerCalls)->toBe(0)
        ->and($container->get('entry'))->toEqual(new Product('deferred'))
        ->and($ownerCalls)->toBe(1);
})->with(['service' => false, 'service method' => true]);
