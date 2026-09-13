<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BootstrapCoreMarker {}

test('extension factories and the completed container share the same core resolution services', function (): void {
    $ids = [ContainerValue::class, AttributeDefinitionRegistry::class, AttributePlanBuilder::class,
        AttributeProcessor::class, ParametersResolver::class, ObjectPipeline::class];
    $seen = [];
    $definition = new AttributeDefinition(BootstrapCoreMarker::class);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(static function (Container $container) use ($ids, &$seen, $definition): AttributeDefinition {
            foreach ($ids as $id) {
                $seen[$id] = $container->get($id);
            }
            return $definition;
        })->build();

    foreach ($ids as $id) {
        expect($container->get($id))->toBe($seen[$id]);
    }
    expect($container->get(AttributeDefinitionRegistry::class)->definition(BootstrapCoreMarker::class))->toBe($definition)
        ->and($container->get(ObjectPipeline::class)->parameters())->toBe($container->get(ParametersResolver::class));
});

test('all built-in value providers participate in the single-source capability policy', function (Closure $callback, string $attribute): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->call($callback))->toThrow(
        AttributeCompositionException::class,
        'accepts at most 1 attribute(s) with capability ' . ValueProvider::class
            . '; found #[' . Config::class . '], #[' . $attribute . '].',
    );
})->with([
    [static fn(#[Config('value'), Env('VALUE')] mixed $value): mixed => $value, Env::class],
    [static fn(#[Config('value'), EntryId('value')] mixed $value): mixed => $value, EntryId::class],
    [static fn(#[Config('value'), Make('value')] mixed $value): mixed => $value, Make::class],
]);
