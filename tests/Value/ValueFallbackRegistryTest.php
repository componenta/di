<?php

declare(strict_types=1);

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackDefinition;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueFallbackRegistry;
use Componenta\DI\Value\ValueResult;

final readonly class OrderedFallback implements ValueFallbackInterface
{
    public function __construct(public string $id) {}

    public function supports(ValueTargetInterface $target): bool { return false; }
    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult { return null; }
}

it('topologically orders fallbacks and preserves registration order as a tie break', function (): void {
    $registry = new ValueFallbackRegistry();
    $first = new OrderedFallback('first');
    $second = new OrderedFallback('second');
    $third = new OrderedFallback('third');

    $registry->add(new ValueFallbackDefinition('second', $second, after: ['first']));
    $registry->add(new ValueFallbackDefinition('first', $first));
    $registry->add(new ValueFallbackDefinition('third', $third, after: ['second']));

    expect(array_map(
        static fn(OrderedFallback $fallback): string => $fallback->id,
        $registry->fallbacks(),
    ))->toBe(['first', 'second', 'third']);
});

it('rejects fallback ordering cycles', function (): void {
    $registry = new ValueFallbackRegistry();
    $registry->add(new ValueFallbackDefinition('a', new OrderedFallback('a'), after: ['b']));
    $registry->add(new ValueFallbackDefinition('b', new OrderedFallback('b'), after: ['a']));

    expect(fn() => $registry->fallbacks())
        ->toThrow(InvalidConfigurationException::class, 'cycle');
});
