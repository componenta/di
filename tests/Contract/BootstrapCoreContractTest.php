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
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BootstrapCoreMarker {}

#[SetUp('configure')]
final class BootstrapCoreSetupTarget
{
    public string $label;

    public function configure(string $label): void
    {
        $this->label = $label;
    }
}

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
    expect($container->getAs(AttributeDefinitionRegistry::class, AttributeDefinitionRegistry::class)->definition(BootstrapCoreMarker::class))->toBe($definition)
        ->and($container->getAs(ObjectPipeline::class, ObjectPipeline::class)->parameters())->toBe($container->getAs(ParametersResolver::class, ParametersResolver::class));
});

test('the exposed object pipeline preserves surrounding parameters for configured setup handlers', function (): void {
    $container = (new ContainerBuilder())->build();
    $pipeline = $container->getAs(ObjectPipeline::class, ObjectPipeline::class);
    $created = $pipeline->create(BootstrapCoreSetupTarget::class, ['label' => 'pipeline']);
    $resolved = $container->makeAs(BootstrapCoreSetupTarget::class, BootstrapCoreSetupTarget::class, ['label' => 'container']);
    if (!$created instanceof BootstrapCoreSetupTarget) {
        throw new \LogicException('Expected the configured setup target.');
    }

    expect($created->label)->toBe('pipeline')
        ->and($resolved->label)->toBe('container');
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
