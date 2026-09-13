<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

final class HandlerTargetFixture
{
    public function run(): void {}
}

test('object handler interfaces reject method targets unsupported by their attribute', function (object $attribute): void {
    $container = (new ContainerBuilder())->build();
    $handler = $container->get(AttributeDefinitionRegistry::class)->definition($attribute::class)?->handler;
    if (!$handler instanceof AttributeHandlerInterface) {
        throw new LogicException('Expected an object handler.');
    }
    $context = handlerTargetContext(HandlerTargetFixture::class);
    $context->initialize(new HandlerTargetFixture());
    $target = new ReflectionMethod(HandlerTargetFixture::class, 'run');
    $shortName = substr($handler::class, strrpos($handler::class, '\\') + 1);

    expect(fn() => $handler->handle($attribute, $target, $context))
        ->toThrow(LogicException::class, $shortName . ' received an unsupported attribute target.');
})->with([
    [new Config()], [new Env()], [new EntryId('value')], [new Cast('int')], [new Make()],
    [new Inject()], [new Init(static fn(): string => 'value')], [new Lazy()], [new NoConstructor()],
]);

test('class creation handlers reject an unrelated attribute even on a class target', function (string $attribute): void {
    if (!class_exists($attribute)) {
        throw new LogicException('Expected a class attribute.');
    }
    $container = (new ContainerBuilder())->build();
    $handler = $container->get(AttributeDefinitionRegistry::class)->definition($attribute)?->handler;
    if (!$handler instanceof AttributeHandlerInterface) {
        throw new LogicException('Expected an object handler.');
    }
    $context = handlerTargetContext(HandlerTargetFixture::class);
    $shortName = substr($handler::class, strrpos($handler::class, '\\') + 1);

    expect(fn() => $handler->handle(new stdClass(), $context->class, $context))
        ->toThrow(LogicException::class, $shortName . ' received an unsupported attribute target.');
})->with([Lazy::class, NoConstructor::class]);

/** @param class-string $class */
function handlerTargetContext(string $class): ObjectCreationContext
{
    return new ObjectCreationContext(new ReflectionClass($class));
}
