<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\BootstrapCoreContract;

use Attribute;
use Closure;
use Componenta\Config\Config as ContainerConfig;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;

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

test('resolution collaborators are private during bootstrap and after construction', function (string $id): void {
    $check = static function (Container $container, string $lookup): void {
        if ($lookup === '') {
            throw new \LogicException('Expected a non-empty service identifier.');
        }

        expect($container->has($lookup))->toBeFalse()
            ->and(fn() => $container->get($lookup))->toThrow(NotFoundException::class)
            ->and(fn() => $container->make($lookup))->toThrow(NotFoundException::class);
    };
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(static function (Container $container) use ($check, $id): AttributeDefinition {
            $check($container, $id);
            return new AttributeDefinition(BootstrapCoreMarker::class);
        })->build();

    $container->alias('runtime.alias', $id);
    $check($container, $id);
    $check($container, strtolower($id));
    $check($container, 'runtime.alias');
})->with([
    AttributeDefinitionRegistry::class,
    AttributePlanBuilder::class,
    AttributeProcessor::class,
    ParametersResolver::class,
    ObjectPipeline::class,
]);

test('extension factories and the completed container share the public configuration services', function (): void {
    $ids = [ContainerConfig::class, Environment::class, ContainerValue::class];
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
});

test('the object pipeline preserves surrounding parameters for configured setup handlers', function (): void {
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $pipeline = $runtime->objects;
    $created = $pipeline->create(BootstrapCoreSetupTarget::class, ['label' => 'pipeline']);
    $resolved = $container->make(BootstrapCoreSetupTarget::class, ['label' => 'container']);
    Assert::assertInstanceOf(BootstrapCoreSetupTarget::class, $resolved);
    if (!$created instanceof BootstrapCoreSetupTarget) {
        throw new \LogicException('Expected the configured setup target.');
    }

    expect($created->label)->toBe('pipeline')
        ->and($resolved->label)->toBe('container');
});

test('configuration and request attributes conflict when both provide the same parameter', function (Closure $callback, string $attribute): void {
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
    [static fn(#[Config('value'), Header('X-Value')] mixed $value): mixed => $value, Header::class],
    [static fn(#[Config('value'), MapQueryString] mixed $value): mixed => $value, MapQueryString::class],
]);
