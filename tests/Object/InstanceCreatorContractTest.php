<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\InstanceCreatorContract;

use Closure;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

final readonly class Dependency {}

final class Product
{
    public int $constructions = 0;

    public function __construct(
        #[EntryId('direct.dependency')]
        public Dependency $dependency,
        public string $label,
        public int $count = 4,
    ) {
        ++$this->constructions;
    }
}

test('standalone instance creation keeps tracked constructor execution out of its public API', function (): void {
    $publicMethods = get_class_methods(InstanceCreator::class);

    expect(array_intersect(['createTracked', 'initializeTracked'], $publicMethods))->toBe([]);
});

test('direct instance creation and initialization resolve explicit arguments alongside attributed dependencies and defaults', function (bool $initialize): void {
    $dependency = new Dependency();
    [$container, $runtime] = (new ContainerBuilder())->addService('direct.dependency', $dependency)->assemble();
    $creator = new InstanceCreator($runtime->parameters);
    $class = new ReflectionClass(Product::class);

    if ($initialize) {
        $entry = $class->newInstanceWithoutConstructor();
        $creator->initialize($entry, $class, ['label' => 'explicit']);
    } else {
        $entry = $creator->create($class, ['label' => 'explicit']);
    }

    expect($entry->dependency)->toBe($dependency)
        ->and($entry->label)->toBe('explicit')
        ->and($entry->count)->toBe(4)
        ->and($entry->constructions)->toBe(1);
})->with(['create' => false, 'initialize' => true]);

final class ConstructorlessProduct
{
    public string $label = 'declared';
}

test('direct constructorless creation uses declared defaults and initialization preserves an existing instance', function (bool $initialize): void {
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $creator = new InstanceCreator($runtime->parameters);
    $class = new ReflectionClass(ConstructorlessProduct::class);

    if ($initialize) {
        $entry = new ConstructorlessProduct();
        $entry->label = 'existing';
        $creator->initialize($entry, $class);
    } else {
        $entry = $creator->create($class);
    }

    expect($entry->label)->toBe($initialize ? 'existing' : 'declared');
})->with(['create' => false, 'initialize' => true]);

abstract class AbstractProduct {}

test('direct creation normalizes native allocation failures for classes without constructors', function (): void {
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $creator = new InstanceCreator($runtime->parameters);

    try {
        $creator->create(new ReflectionClass(AbstractProduct::class));
    } catch (ResolutionException $error) {
        expect($error->serviceId)->toBe(AbstractProduct::class)
            ->and($error->getPrevious())->toBeInstanceOf(\Error::class)
            ->and($error->getPrevious()?->getMessage())->toBe('Cannot instantiate abstract class ' . AbstractProduct::class);
        return;
    }
    throw new LogicException('Native allocation failure must be reported as a service resolution failure.');
});

final class ReflectedProduct
{
    public string $label;

    public function __construct(string $label = 'native')
    {
        $this->label = 'constructor:' . $label;
    }

    public function replace(string $label = 'replacement'): void
    {
        $this->label = 'method:' . $label;
    }
}

/** @extends ReflectionClass<ReflectedProduct> */
final class BorrowedConstructor extends ReflectionClass
{
    public function __construct(private readonly ReflectionMethod $method)
    {
        parent::__construct(ReflectedProduct::class);
    }

    public function getConstructor(): ReflectionMethod
    {
        return $this->method;
    }
}

test('direct initialization invokes the constructor selected before parameter resolution', function (): void {
    $method = new ReflectionMethod(ReflectedProduct::class, '__construct');
    $class = new BorrowedConstructor($method);
    $resolver = new class ($method) implements ParameterResolverInterface {
        public function __construct(private readonly ReflectionMethod $method) {}

        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'label';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            $this->method->__construct(ReflectedProduct::class, 'replace');
            return [$target->position, 'resolved'];
        }
    };
    [, $runtime] = (new ContainerBuilder())->addParameterResolver($resolver, 1300)->assemble();
    $entry = new ReflectionClass(ReflectedProduct::class)->newInstanceWithoutConstructor();

    new InstanceCreator($runtime->parameters)->initialize($entry, $class);

    expect($method->getName())->toBe('replace')
        ->and($entry->label)->toBe('constructor:resolved');
});

test('standalone construction ignores metadata for another method', function (bool $initialize): void {
    [, $runtime] = (new ContainerBuilder())->assemble();
    $creator = new InstanceCreator($runtime->parameters);
    $class = new BorrowedConstructor(new ReflectionMethod(ReflectedProduct::class, 'replace'));

    if ($initialize) {
        $entry = $class->newInstanceWithoutConstructor();
        $creator->initialize($entry, $class);
    } else {
        $entry = $creator->create($class);
    }

    expect($entry->label)->toBe('constructor:native');
})->with(['create' => false, 'initialize' => true]);

final class StandaloneInputProduct
{
    /** @param array{label: string} $nested */
    public function __construct(public string $label, array $nested)
    {
        $nested['label'] = 'written by the constructor';
    }
}

/** @extends ReflectionClass<StandaloneInputProduct> */
final class StandaloneInputMetadata extends ReflectionClass
{
    /** @param Closure():void $onRead */
    public function __construct(private readonly Closure $onRead)
    {
        parent::__construct(StandaloneInputProduct::class);
    }

    #[\Override]
    public function getConstructor(): ?ReflectionMethod
    {
        ($this->onRead)();
        return parent::getConstructor();
    }
}

test('standalone constructors capture explicit argument slots before reading metadata', function (bool $initialize): void {
    [, $runtime] = (new ContainerBuilder())->assemble();
    $creator = new InstanceCreator($runtime->parameters);
    $label = 'initial argument';
    $nested = 'initial nested reference';
    $class = new StandaloneInputMetadata(static function () use (&$label): void {
        $label = 'changed during metadata discovery';
    });
    $arguments = ['label' => &$label, 'nested' => ['label' => &$nested]];

    if ($initialize) {
        $entry = $class->newInstanceWithoutConstructor();
        $creator->initialize($entry, $class, $arguments);
    } else {
        $entry = $creator->create($class, $arguments);
    }

    expect($label)->toBe('changed during metadata discovery')
        ->and($entry->label)->toBe('initial argument')
        ->and($nested)->toBe('written by the constructor');
})->with(['create' => false, 'initialize' => true]);
