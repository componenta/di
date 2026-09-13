<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\NativeTypeAcceptance;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

interface Left {}
interface Right {}
final class Both implements Left, Right {}
final class LeftOnly implements Left {}
enum Choice
{
    case One;
}

/** @param \Closure(mixed): mixed $call */
test('explicit parameters follow strict native PHP type acceptance', function (\Closure $call): void {
    $container = (new ContainerBuilder())->build();
    $values = [null, true, false, 0, 1.25, '', '1', 'text', [], [1], new \stdClass(),
        new \ArrayIterator([1]), static fn(): int => 7, 'strlen', new Both(), new LeftOnly(), Choice::One];

    foreach ($values as $value) {
        $accepted = true;
        $expected = null;
        try {
            $expected = $call($value);
        } catch (\TypeError) {
            $accepted = false;
        }

        foreach (['value', 0] as $key) {
            if ($accepted) {
                expect($container->call($call, [$key => $value]))->toBe($expected);
            } else {
                expect(fn() => $container->call($call, [$key => $value]))
                    ->toThrow(ResolutionException::class);
            }
        }
    }
})->with([
    'untyped' => [static fn($value): mixed => $value],
    'mixed' => [static fn(mixed $value): mixed => $value],
    'null' => [static fn(null $value): mixed => $value],
    'true' => [static fn(true $value): mixed => $value],
    'false' => [static fn(false $value): mixed => $value],
    'bool' => [static fn(bool $value): mixed => $value],
    'int' => [static fn(int $value): mixed => $value],
    'float' => [static fn(float $value): mixed => $value],
    'string' => [static fn(string $value): mixed => $value],
    'array' => [static fn(array $value): mixed => $value],
    'object' => [static fn(object $value): mixed => $value],
    'callable' => [static fn(callable $value): mixed => $value],
    'iterable' => [static fn(iterable $value): mixed => $value],
    'nullable' => [static fn(?int $value): mixed => $value],
    'union' => [static fn(int|string $value): mixed => $value],
    'interface' => [static fn(Left $value): mixed => $value],
    'intersection' => [static fn(Left&Right $value): mixed => $value],
    'DNF' => [static fn((Left&Right)|string $value): mixed => $value],
    'enum' => [static fn(Choice $value): mixed => $value],
]);
