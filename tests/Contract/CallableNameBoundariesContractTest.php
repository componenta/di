<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\CallableResolver;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final class CallableCollision
{
    public static function Bgo(int $left = 7): int
    {
        return $left + self::Bhidden();
    }

    private static function Bhidden(): int
    {
        return 0;
    }
}

final class CallableCollisionB
{
    public static function go(string $right = 'eight'): string
    {
        return $right;
    }

    public function hidden(): string
    {
        return 'instance';
    }
}

final class MissingCollisionServices implements ContainerInterface
{
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): mixed
    {
        throw new LogicException('No registered service.');
    }
}

test('callable parameter plans distinguish class and method boundaries in similar names', function (): void {
    $container = (new ContainerBuilder())->build();

    foreach ([1, 2] as $_attempt) {
        expect($container->call([CallableCollision::class, 'Bgo']))->toBe(7)
            ->and($container->call([CallableCollisionB::class, 'go']))->toBe('eight');
    }
});

test('callable method classification distinguishes similar combined class and method names', function (): void {
    $resolver = new CallableResolver(new MissingCollisionServices());

    expect(fn() => $resolver->resolve([CallableCollision::class, 'Bhidden']))
        ->toThrow(InvalidCallableException::class, 'Method "' . CallableCollision::class . '::Bhidden()" does not exist.')
        ->and(fn() => $resolver->resolve([CallableCollisionB::class, 'hidden']))
        ->toThrow(InvalidCallableException::class, 'Service "' . CallableCollisionB::class . '" is not defined in the container.')
        ->and(fn() => $resolver->resolve(CallableCollisionB::class . '::hidden::extra'))
        ->toThrow(InvalidCallableException::class, 'Method "' . CallableCollisionB::class . '::hidden::extra()" does not exist.');
});

test('callable resolution requires visibility from application scope', function (): void {
    $container = (new ContainerBuilder())->build();
    $resolver = new CallableResolver($container);
    $container->set('internal.specification', [$resolver, 'resolveString']);
    $container->set('internal.receiver', $resolver);

    $specifications = [
        [$resolver, 'resolveString'],
        [$resolver, 'isStaticMethod'],
        [CallableResolver::class, 'classOrInterfaceExists'],
        CallableResolver::class . '::classOrInterfaceExists',
        ['internal.receiver', 'resolveString'],
        'internal.specification',
    ];

    foreach ($specifications as $specification) {
        expect(fn() => $resolver->resolve($specification))->toThrow(InvalidCallableException::class);
    }
    expect($resolver->resolve([$resolver, 'resolve']))->toBe([$resolver, 'resolve']);
});
