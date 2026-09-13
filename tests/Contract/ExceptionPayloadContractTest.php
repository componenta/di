<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Closure;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionProperty;
use RuntimeException;

final class ExceptionPayloadProperties
{
    public string $typed;
    /** @var mixed */
    public $untyped;
}

test('circular dependency diagnostics retain a complete chain and default exception metadata', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->alias('first', 'second');
    $container->alias('second', 'third');

    try {
        $container->alias('third', 'first');
    } catch (CircularDependencyException $error) {
        expect($error->chain)->toBe(['third', 'first', 'second', 'third'])
            ->and($error->getMessage())->toBe('Circular alias reference: third -> first -> second -> third.')
            ->and($error->getPrevious())->toBeNull()
            ->and($error->getCode())->toBe(0);
        $container->set('third', 'still-valid');
        expect($container->get('first'))->toBe('still-valid');
        return;
    }
    throw new LogicException('A cyclic alias must be rejected before it changes the map.');
});

test('circular service exceptions use a default message or preserve an explicit diagnostic', function (bool $custom): void {
    $cause = new RuntimeException('root cause');
    $error = $custom
        ? new CircularDependencyException(['a', 'b', 'a'], 'custom cycle', $cause)
        : new CircularDependencyException(['a', 'b', 'a'], previous: $cause);

    expect($error->chain)->toBe(['a', 'b', 'a'])
        ->and($error->getMessage())->toBe($custom ? 'custom cycle' : 'Circular dependency detected: a -> b -> a.')
        ->and($error->getPrevious())->toBe($cause)
        ->and($error->getCode())->toBe(0);
})->with([false, true]);

test('callable diagnostics describe closures and object method owners without retaining them as metadata', function (mixed $value, string $description, string $type): void {
    $error = new InvalidCallableException($value);
    expect($error->callableDescription)->toBe($description)
        ->and($error->callableType)->toBe($type)
        ->and($error->getMessage())->toBe('')
        ->and($error->getCode())->toBe(0)
        ->and($error->getPrevious())->toBeNull();
})->with([
    [static fn(): int => 1, 'Closure', Closure::class],
    [[new ExceptionPayloadProperties(), 'missing'], ExceptionPayloadProperties::class . '::missing', 'array'],
]);

test('missing-service failures use a neutral exception code and preserve the requested id', function (): void {
    $container = (new ContainerBuilder())->build();
    try {
        $container->get('missing.payload.service');
    } catch (NotFoundException $error) {
        expect($error->getCode())->toBe(0)
            ->and($error->getPrevious())->toBeNull()
            ->and($error->getMessage())->toBe('Service "missing.payload.service" is not defined in the container.');
        return;
    }
    throw new LogicException('The missing service should fail.');
});

test('delegator failures retain the cause without inheriting its application error code', function (): void {
    $cause = new RuntimeException('delegator unavailable', 42);
    $container = (new ContainerBuilder())->addService('entry', 'value')->build();
    $container->delegator('entry', static fn() => throw $cause);

    try {
        $container->get('entry');
    } catch (DelegatorException $error) {
        expect($error->getCode())->toBe(0)
            ->and($error->getPrevious())->toBe($cause);
        return;
    }
    throw new LogicException('The failing delegator should propagate a diagnostic.');
});

test('property diagnostic factories preserve declared types and format reason and cause together', function (string $name, ?string $type, bool $withCause): void {
    $cause = $withCause ? new RuntimeException('provider unavailable') : null;
    $error = ResolutionException::forProperty(new ReflectionProperty(ExceptionPayloadProperties::class, $name), 'source failed', $cause);

    expect($error->property?->name)->toBe($name)
        ->and($error->property?->class)->toBe(ExceptionPayloadProperties::class)
        ->and($error->property?->type)->toBe($type)
        ->and($error->propertyType)->toBe($type)
        ->and($error->getPrevious())->toBe($cause)
        ->and($error->getMessage())->toBe(
            'Cannot resolve property "' . ExceptionPayloadProperties::class . '::$' . $name . '": source failed'
                . ($withCause ? ' (RuntimeException: provider unavailable).' : '.'),
        );
})->with([
    ['typed', 'string', false],
    ['typed', 'string', true],
    ['untyped', null, false],
    ['untyped', null, true],
]);
