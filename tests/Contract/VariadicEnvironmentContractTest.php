<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;

use function Componenta\DI\Tests\Support\container;

#[SetUp('initialize', ['ids' => new Env('IDS')])]
final class VariadicEnvSetUp
{
    /** @var array<array-key, int> */
    public array $ids;

    public function initialize(int ...$ids): void
    {
        $this->ids = $ids;
    }
}

#[SetUp('initialize', ['ids' => new EnvironmentEntry('IDS')])]
final class VariadicEnvironmentEntrySetUp
{
    /** @var array<array-key, int> */
    public array $ids;

    public function initialize(int ...$ids): void
    {
        $this->ids = $ids;
    }
}

test('environment variadic sources resolve the argument collection', function (): void {
    $container = container(config: new Config([], new Environment(['IDS' => [1, 2]])));
    expect($container->call(static fn(#[Env('IDS')] int ...$ids): array => $ids))->toBe([1, 2])
        ->and($container->make(VariadicEnvSetUp::class)->ids)->toBe([1, 2])
        ->and($container->make(VariadicEnvironmentEntrySetUp::class)->ids)->toBe([1, 2]);
});

test('environment variadic sources keep empty arrays and validate individual elements', function (): void {
    $empty = container(config: new Config([], new Environment(['IDS' => []])));
    expect($empty->call(static fn(#[Env('IDS')] int ...$ids): array => $ids))->toBe([]);

    $invalid = container(config: new Config([], new Environment(['IDS' => [1, 'invalid']])));
    expect(fn() => $invalid->call(static fn(#[Env('IDS')] int ...$ids): array => $ids))
        ->toThrow(ResolutionException::class, 'declared element type');
});
