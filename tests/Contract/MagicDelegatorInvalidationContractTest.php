<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use function Componenta\DI\Tests\Support\container;

final class MagicDelegatorOwner
{
    public function __construct(private readonly string $suffix) {}

    protected static function decorate(string $unused): never
    {
        throw new \LogicException($unused);
    }

    /** @param array<array-key, mixed> $arguments */
    public function __call(string $method, array $arguments): string
    {
        if ($method !== 'decorate' || !is_string($arguments[0] ?? null)) {
            throw new \LogicException('Unexpected magic delegation.');
        }

        return $arguments[0] . $this->suffix;
    }
}

test('replacing an instance used by magic method syntax invalidates its delegator result', function (bool $arraySyntax): void {
    $container = container(['services' => [
        'decorated' => 'base',
        MagicDelegatorOwner::class => new MagicDelegatorOwner(':first'),
    ]]);
    $container->delegator('decorated', $arraySyntax
        ? [MagicDelegatorOwner::class, 'decorate']
        : MagicDelegatorOwner::class . '::decorate');
    expect($container->get('decorated'))->toBe('base:first');

    $container->set(MagicDelegatorOwner::class, new MagicDelegatorOwner(':second'));

    expect($container->get('decorated'))->toBe('base:second');
})->with(['string' => false, 'array' => true]);
