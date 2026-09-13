<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallablePlanIsolation;

use function Componenta\DI\Tests\Support\container;

function increment(int $number): int
{
    return $number + 1;
}

function uppercase(string $label): string
{
    return strtoupper($label);
}

final class Incrementer
{
    public function format(int $number): int
    {
        return $number + 1;
    }

    public function __invoke(int $number): int
    {
        return $this->format($number);
    }
}

final class Uppercaser
{
    public function format(string $label): string
    {
        return strtoupper($label);
    }

    public function __invoke(string $label): string
    {
        return $this->format($label);
    }
}

test('cached argument plans remain specific to each callable signature', function (callable $first, callable $second): void {
    $container = container();

    expect($container->call($first, ['number' => 4]))->toBe(5)
        ->and($container->call($second, ['label' => 'second']))->toBe('SECOND')
        ->and($container->call($first, ['number' => 7]))->toBe(8);
})->with([
    'functions' => [__NAMESPACE__ . '\\increment', __NAMESPACE__ . '\\uppercase'],
    'same method name in different classes' => [[new Incrementer(), 'format'], [new Uppercaser(), 'format']],
    'invokable objects of different classes' => [new Incrementer(), new Uppercaser()],
]);
