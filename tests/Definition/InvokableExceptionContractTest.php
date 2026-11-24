<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Definition\InvokableExceptionContract;

use Closure;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ResolutionException;
use LogicException;
use RuntimeException;
use Throwable;

use function Componenta\DI\Tests\Support\container;

final class Product
{
    public static ?Closure $construct = null;
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
        if (self::$construct !== null) {
            (self::$construct)();
        }
    }
}

final class ConstructionState
{
    public ?Throwable $cause = null;
}

test('invokable constructor recursion preserves the original cycle diagnostic and permits recovery', function (bool $fresh): void {
    $di = container();
    $di->set('product', Definition::invokable(Product::class));
    $read = static fn(): mixed => $fresh ? $di->make('product') : $di->get('product');
    $state = new ConstructionState();
    Product::$constructions = 0;
    Product::$construct = static function () use ($read, $state): void {
        try {
            $read();
        } catch (Throwable $exception) {
            $state->cause = $exception;
            throw $exception;
        }
    };

    try {
        $failure = null;
        try {
            $read();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $cause = $state->cause;
        expect($cause)->toBeInstanceOf(CircularDependencyException::class);
        if (!$cause instanceof CircularDependencyException) {
            throw new LogicException('The recursive constructor must reach the cycle guard.');
        }
        expect($failure)->toBeInstanceOf(CircularDependencyException::class)
            ->toBe($cause)
            ->and($cause->chain)->toBe(['product', 'product'])
            ->and($cause->getPrevious())->toBeNull()
            ->and(Product::$constructions)->toBe(1);

        Product::$construct = null;

        expect($read())->toBeInstanceOf(Product::class)
            ->and(Product::$constructions)->toBe(2);
    } finally {
        Product::$construct = null;
    }
})->with(['shared get' => false, 'fresh make' => true]);

test('foreign invokable constructor failures retain their original cause and permit recovery', function (bool $fresh): void {
    $di = container();
    $di->set('product', Definition::invokable(Product::class));
    $read = static fn(): mixed => $fresh ? $di->make('product') : $di->get('product');
    $cause = new RuntimeException('invokable constructor unavailable');
    Product::$constructions = 0;
    Product::$construct = static fn(): never => throw $cause;

    try {
        $failure = null;
        try {
            $read();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ResolutionException::class);
        if (!$failure instanceof ResolutionException) {
            throw new LogicException('The foreign constructor failure must be normalized.');
        }
        expect($failure->serviceId)->toBe('product')
            ->and($failure->getPrevious())->toBe($cause)
            ->and(Product::$constructions)->toBe(1);

        Product::$construct = null;

        expect($read())->toBeInstanceOf(Product::class)
            ->and(Product::$constructions)->toBe(2);
    } finally {
        Product::$construct = null;
    }
})->with(['shared get' => false, 'fresh make' => true]);
