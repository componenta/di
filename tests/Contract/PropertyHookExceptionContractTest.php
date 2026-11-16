<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PropertyHookException;

use Closure;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use RuntimeException;
use Throwable;

final class HookState
{
    public ?Closure $beforeWrite = null;
    public ?Throwable $cause = null;
    public int $constructions = 0;
    public int $writes = 0;
}

final class Product
{
    public function __construct(private HookState $state)
    {
        ++$state->constructions;
    }

    #[EntryId('hook-input')]
    public string $value = 'unconfigured' {
        set {
            ++$this->state->writes;
            $this->state->beforeWrite?->__invoke();
            $this->value = $value;
        }
    }
}

test('property hook recursion preserves the original cycle diagnostic and permits recovery', function (bool $fresh): void {
    $state = new HookState();
    $di = (new ContainerBuilder())
        ->addService(HookState::class, $state)
        ->addService('hook-input', 'configured')
        ->build();
    $read = static fn(): mixed => $fresh ? $di->make(Product::class) : $di->get(Product::class);
    $state->beforeWrite = static function () use ($read, $state): void {
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
            throw new LogicException('The recursive property hook must reach the cycle guard.');
        }
        expect($failure)->toBeInstanceOf(CircularDependencyException::class)
            ->toBe($cause)
            ->and($cause->chain)->toBe([Product::class, Product::class])
            ->and($cause->getPrevious())->toBeNull()
            ->and($state->constructions)->toBe(1)
            ->and($state->writes)->toBe(1);

        $state->beforeWrite = null;
        $product = $read();
        expect($product)->toBeInstanceOf(Product::class);
        if (!$product instanceof Product) {
            throw new LogicException('Expected a configured product after retry.');
        }
        expect($product->value)->toBe('configured')
            ->and($state->constructions)->toBe(2)
            ->and($state->writes)->toBe(2);
    } finally {
        $state->beforeWrite = null;
    }
})->with(['shared get' => false, 'fresh make' => true]);

test('foreign property hook failures retain the property diagnostic and permit recovery', function (bool $fresh): void {
    $state = new HookState();
    $cause = new RuntimeException('property hook unavailable');
    $di = (new ContainerBuilder())
        ->addService(HookState::class, $state)
        ->addService('hook-input', 'configured')
        ->build();
    $read = static fn(): mixed => $fresh ? $di->make(Product::class) : $di->get(Product::class);
    $state->beforeWrite = static fn(): never => throw $cause;

    try {
        $failure = null;
        try {
            $read();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ResolutionException::class);
        if (!$failure instanceof ResolutionException) {
            throw new LogicException('The foreign property hook failure must be normalized.');
        }
        expect($failure->property?->class)->toBe(Product::class)
            ->and($failure->property?->name)->toBe('value')
            ->and($failure->propertyType)->toBe('string')
            ->and($failure->getPrevious())->toBe($cause)
            ->and($state->constructions)->toBe(1)
            ->and($state->writes)->toBe(1);

        $state->beforeWrite = null;
        $product = $read();
        expect($product)->toBeInstanceOf(Product::class);
        if (!$product instanceof Product) {
            throw new LogicException('Expected a configured product after retry.');
        }
        expect($product->value)->toBe('configured')
            ->and($state->constructions)->toBe(2)
            ->and($state->writes)->toBe(2);
    } finally {
        $state->beforeWrite = null;
    }
})->with(['shared get' => false, 'fresh make' => true]);
