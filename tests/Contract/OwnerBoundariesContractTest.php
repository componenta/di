<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\OwnerBoundaries;

use Attribute;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Container;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\InvokableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\Reflection\Reflection;
use DomainException;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

final class EmptyProduct {}

#[Attribute]
final class Marker {}

final readonly class ConfiguredProduct
{
    /** @param array<string,mixed> $values */
    public function __construct(public array $values) {}
}

final readonly class RequestedProduct
{
    public function __construct(public string $label) {}
}

final readonly class OtherProduct
{
    public function __construct(public string $label) {}
}

test('availability reports failures independently of a missing requested entry', function (): void {
    $failure = new DomainException('provider unavailable');
    $resolver = new class ($failure) implements EntryResolverInterface {
        public function __construct(private readonly DomainException $failure) {}

        public function can(string $id): bool
        {
            if ($id === 'offline') {
                throw $this->failure;
            }
            return false;
        }

        public function resolve(string $id, EntryParameters $params): mixed
        {
            throw new LogicException('Only a selected entry can be resolved.');
        }
    };
    $container = new Container($resolver, new ContainerBuilder()->build());

    foreach ([$container->has(...), $container->get(...)] as $lookup) {
        try {
            $lookup('offline');
        } catch (ResolutionException $error) {
            expect($error->serviceId)->toBe('offline')->and($error->getPrevious())->toBe($failure);
            continue;
        }
        throw new LogicException('Expected the provider failure to reach the consumer.');
    }

    expect($container->has('missing'))->toBeFalse()
        ->and(fn() => $container->get('missing'))->toThrow(NotFoundException::class);
});

test('standalone seed validation rejects protected ids before claiming the resolver', function (bool $alias): void {
    $root = new CompositeResolver(new InvokableResolver());
    $executor = new ContainerBuilder()->build();
    $id = $alias ? 'application.config' : Config::class;

    expect(fn() => new Container($root, $executor, $alias ? [$id => Config::class] : [], [$id => 'invalid config']))
        ->toThrow(InvalidConfigurationException::class, 'protected DI id');

    $root->setDefinition('product', new InvokableDefinition(EmptyProduct::class));
    $container = new Container($root, $executor, services: ['seed' => null]);
    expect($container->get('product'))->toBeInstanceOf(EmptyProduct::class)
        ->and($container->has('seed'))->toBeTrue()
        ->and($container->get('seed'))->toBeNull();
})->with(['direct seed' => false, 'seed through alias' => true]);

test('object creation keeps the accepted native class throughout parameter resolution', function (bool $reflectObject): void {
    $class = $reflectObject ? new \ReflectionObject(new RequestedProduct('source')) : new ReflectionClass(RequestedProduct::class);
    $parameters = new ParametersResolver(new AttributePlanBuilder(new AttributeDefinitionRegistry()));
    $parameters->add(new class ($class) implements ParameterResolverInterface {
        /** @param ReflectionClass<covariant object> $class */
        public function __construct(private readonly ReflectionClass $class) {}

        public function supports(ParameterTarget $target): bool
        {
            $subject = $this->class instanceof \ReflectionObject ? new OtherProduct('source') : OtherProduct::class;
            new ReflectionMethod($this->class, '__construct')->invoke($this->class, $subject);
            return $target->name === 'label';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [$target->position, 'resolved'];
        }
    });

    $entry = new InstanceCreator($parameters)->create($class);

    expect($class->getName())->toBe(OtherProduct::class)
        ->and($entry)->toEqual(new RequestedProduct('resolved'));
})->with(['class reflector' => false, 'object reflector' => true]);

test('a validated class definition keeps its owned nested argument graph internal', function (): void {
    $child = ['label' => 'original'];
    $parameters = ['values' => ['first' => &$child, 'second' => &$child]];
    $factory = new FactoryResolver(new ContainerBuilder()->build(), new ProxyFactory());
    $definition = $factory->validateDefinition('configured', ClassDefinition::create(ConfiguredProduct::class)->constructor($parameters));

    $child['label'] = 'caller update';
    if ($definition instanceof ClassDefinition) {
        $visible = $definition->constructorParams;
        $values = $visible['values'] ?? null;
        if (is_array($values)) {
            $values['first'] = ['label' => 'update through a read'];
        }
    }

    $expected = ['first' => ['label' => 'original'], 'second' => ['label' => 'original']];
    expect($factory->resolveDefinition('configured', $definition))->toEqual(new ConfiguredProduct($expected))
        ->and($factory->supportsDefinition($definition))->toBeFalse();
});

test('facades with mutable runtime owners reject ambiguous cloning', function (): void {
    $root = new CompositeResolver(new InvokableResolver());
    $root->setDefinition('product', new InvokableDefinition(EmptyProduct::class));
    $registry = new AttributeDefinitionRegistry();

    expect(fn() => clone $root)->toThrow(\Error::class)
        ->and(fn() => clone $registry)->toThrow(\Error::class);

    $snapshot = new CompositeResolver($root);
    $registry->register(new AttributeDefinition(Marker::class));
    expect($snapshot->resolve('product'))->toBeInstanceOf(EmptyProduct::class)
        ->and($registry->definition(Marker::class))->toBeInstanceOf(AttributeDefinition::class);
});

function requestedDefault(string $value = 'requested'): string
{
    return $value;
}

function otherDefault(string $value = 'other'): string
{
    return $value;
}

final class IncompatibleFactory
{
    public static function create(string $context): EmptyProduct
    {
        return new EmptyProduct();
    }
}

final class CompatibleFactory
{
    public static function create(): EmptyProduct
    {
        return new EmptyProduct();
    }
}

test('cold and warmed containers reflect the actual requested callable independently of a dependency cache', function (): void {
    $name = __NAMESPACE__ . '\\requestedDefault';
    $warm = new ContainerBuilder()->build();
    $cold = new ContainerBuilder()->build();
    expect($warm->call($name))->toBe('requested');

    $borrowed = Reflection::callable($name);
    if (!$borrowed instanceof ReflectionFunction) {
        throw new LogicException('Expected a named function reflector.');
    }
    try {
        try {
            new ReflectionMethod($borrowed, '__construct')->invoke($borrowed, __NAMESPACE__ . '\\otherDefault');
        } catch (LogicException) {
            // Updated reflection providers guard shared handles at their own boundary.
        }
        expect($cold->call($name))->toBe('requested')->and($warm->call($name))->toBe('requested');
    } finally {
        Reflection::clearReflectors();
    }
});

test('factory ABI admission reflects the selected declaration independently of a dependency cache', function (): void {
    $callable = [IncompatibleFactory::class, 'create'];
    $borrowed = Reflection::callable($callable);
    if (!$borrowed instanceof ReflectionMethod) {
        throw new LogicException('Expected a named factory method reflector.');
    }
    try {
        try {
            new ReflectionMethod($borrowed, '__construct')->invoke($borrowed, CompatibleFactory::class, 'create');
        } catch (LogicException) {
            // Updated reflection providers guard shared handles at their own boundary.
        }
        $container = new ContainerBuilder()->build();
        expect(fn() => $container->set('factory', new FactoryDefinition($callable)))
            ->toThrow(InvalidConfigurationException::class, 'incompatible')
            ->and($container->has('factory'))->toBeFalse();
    } finally {
        Reflection::clearReflectors();
    }
});

test('native invokable objects retain their signature when PHP accepts a private invoke method', function (): void {
    // PHP emits a declaration warning and still accepts this object as a callable.
    set_error_handler(static fn(int $severity, string $message): bool => $severity === E_WARNING
        && str_contains($message, '__invoke() must have public visibility'));
    try {
        $invokable = eval('return new class { private function __invoke(\\stdClass $value): object { return $value; } };');
    } finally {
        restore_error_handler();
    }
    if (!is_object($invokable) || !is_callable($invokable)) {
        throw new LogicException('Expected a native invokable object.');
    }

    $value = new \stdClass();
    $container = new ContainerBuilder()->addService(\stdClass::class, $value)->build();
    expect($container->call($invokable))->toBe($value)
        ->and(fn() => $container->set('factory', new FactoryDefinition($invokable)))
        ->toThrow(InvalidConfigurationException::class, 'incompatible');
});
