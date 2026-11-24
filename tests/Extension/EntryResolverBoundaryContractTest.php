<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Extension\EntryResolverBoundaryContract;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

abstract class AbstractProduct {}

final readonly class ForeignFactoryDefinition implements DefinitionInterface
{
    /** @param Closure():object $factory */
    public function __construct(public Closure $factory) {}
}

test('direct reflection resolution rejects unavailable entries without a preceding can check', function (string $id): void {
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $objects = $runtime->objects;
    if (!$objects instanceof ObjectPipeline) {
        throw new LogicException('Expected the configured object pipeline.');
    }
    $resolver = new ReflectionResolver($objects, [\stdClass::class]);

    expect(fn() => $resolver->resolve($id))->toThrow(NotFoundException::class);
})->with([
    'unknown class' => __NAMESPACE__ . '\\MissingProduct',
    'abstract class' => AbstractProduct::class,
    'explicit exclusion' => \stdClass::class,
]);

test('direct factory validation rejects foreign descriptors even when they expose a callable factory', function (): void {
    $strategy = new FactoryResolver((new ContainerBuilder())->build(), new ProxyFactory());
    $definition = new ForeignFactoryDefinition(static fn(): object => new \stdClass());

    expect(fn() => $strategy->validateDefinition('product', $definition))
        ->toThrow(InvalidConfigurationException::class);
});
