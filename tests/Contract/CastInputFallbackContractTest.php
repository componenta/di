<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CastInputFallback;

use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class FallbackCaster implements CasterInterface, CasterProviderInterface
{
    /** @var list<mixed> */
    public array $inputs = [];

    public string $name { get => 'fallback'; }

    public function provide(string $name): ?CasterInterface
    {
        return $name === $this->name ? $this : null;
    }

    public function cast(mixed $value): string
    {
        $this->inputs[] = $value;

        return match (true) {
            $value === null => 'cast-null',
            is_string($value) => trim($value),
            default => throw new \InvalidArgumentException('Expected a string or null.'),
        };
    }
}

test('Cast uses the declared fallback before invoking the caster once', function (
    Closure $target,
    mixed $input,
    string $expected,
): void {
    $caster = new FallbackCaster();
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, $caster)
        ->build();

    expect($container->call($target))->toBe($expected)
        ->and($caster->inputs)->toBe([$input]);
})->with([
    'native default' => [
        static fn(#[Cast('fallback')] string $value = '  native  '): string => $value,
        '  native  ',
        'native',
    ],
    'nullable without a default' => [
        static fn(#[Cast('fallback')] ?string $value): ?string => $value,
        null,
        'cast-null',
    ],
    'attribute default precedes native default' => [
        static fn(#[Cast('fallback', default: '  attribute  ')] string $value = 'native'): string => $value,
        '  attribute  ',
        'attribute',
    ],
]);

test('Cast rejects a missing required input before invoking the caster', function (): void {
    $caster = new FallbackCaster();
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, $caster)
        ->build();

    expect(fn() => $container->call(static fn(#[Cast('fallback')] string $value): string => $value))
        ->toThrow(ResolutionException::class)
        ->and($caster->inputs)->toBe([]);
});
