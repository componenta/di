<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

#[Attribute(Attribute::TARGET_ALL)]
final class ForeignHandlerAttribute {}

final class ForeignHandlerProperty
{
    #[ForeignHandlerAttribute]
    public string $value = 'untouched';
}

test('a misregistered built-in parameter handler rejects an unrelated attribute before using caller input', function (string $attribute): void {
    if (!class_exists($attribute)) {
        throw new LogicException('Expected a built-in attribute.');
    }
    $source = (new ContainerBuilder())->build();
    $handler = $source->get(AttributeDefinitionRegistry::class)->definition($attribute)?->handler;
    if ($handler === null) {
        throw new LogicException('Expected a built-in handler.');
    }
    $shortName = substr($handler::class, strrpos($handler::class, '\\') + 1);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ForeignHandlerAttribute::class, $handler))
        ->build();

    try {
        $container->call(static fn(#[ForeignHandlerAttribute] string $value): string => $value, ['value' => 'caller']);
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBeInstanceOf(LogicException::class)
            ->and($error->getPrevious()?->getMessage())->toBe($shortName . ' received an unsupported parameter attribute.');
        return;
    }
    throw new LogicException('An incompatible parameter handler registration must fail.');
})->with([Config::class, Env::class, EntryId::class, Cast::class, Make::class]);

test('a misregistered built-in object handler reports an unsupported attribute target', function (string $attribute): void {
    if (!class_exists($attribute)) {
        throw new LogicException('Expected a built-in attribute.');
    }
    $source = (new ContainerBuilder())->build();
    $handler = $source->get(AttributeDefinitionRegistry::class)->definition($attribute)?->handler;
    if ($handler === null) {
        throw new LogicException('Expected a built-in handler.');
    }
    $shortName = substr($handler::class, strrpos($handler::class, '\\') + 1);
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ForeignHandlerAttribute::class, $handler))
        ->build();

    try {
        $container->make(ForeignHandlerProperty::class);
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBeInstanceOf(LogicException::class)
            ->and($error->getPrevious()?->getMessage())->toBe($shortName . ' received an unsupported attribute target.')
            ->and($error->propertyClass)->toBe(ForeignHandlerProperty::class)
            ->and($error->propertyName)->toBe('value');
        return;
    }
    throw new LogicException('An incompatible object handler registration must fail.');
})->with([Config::class, Env::class, EntryId::class, Cast::class, Make::class, Inject::class, Init::class, Lazy::class, NoConstructor::class]);
