<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use InvalidArgumentException;

final readonly class ConstructorEntryResolverForTest implements EntryResolverInterface
{
    public function __construct(
        private string $id,
        private string $value,
    ) {}

    public function can(string $id): bool
    {
        return $id === $this->id;
    }

    public function resolve(string $id, array $context = []): string
    {
        return $this->value;
    }
}

it('preserves resolver order supplied through the constructor', function () {
    $first = new ConstructorEntryResolverForTest('entry', 'first');
    $second = new ConstructorEntryResolverForTest('entry', 'second');
    $resolver = new CompositeResolver($first, $second);

    expect($resolver->resolve('entry'))->toBe('first');
});

it('normalizes named variadic arguments without changing their call order', function () {
    $first = new ConstructorEntryResolverForTest('entry', 'first');
    $second = new ConstructorEntryResolverForTest('entry', 'second');
    $resolver = new CompositeResolver(second: $second, first: $first);

    expect($resolver->resolve('entry'))->toBe('second');
});

it('rejects duplicate resolver identities supplied through the constructor', function () {
    $resolver = new ConstructorEntryResolverForTest('entry', 'value');

    expect(fn () => new CompositeResolver($resolver, $resolver))
        ->toThrow(InvalidArgumentException::class);
});
