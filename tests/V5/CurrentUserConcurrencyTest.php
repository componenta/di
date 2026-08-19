<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Resolver\CurrentUserProvider;
use Fiber;

final readonly class AuditFiberUser
{
    public function __construct(public string $name) {}
}

test('default current user provider isolates explicitly assigned Fiber users', function (): void {
    $main = new AuditFiberUser('main');
    $firstUser = new AuditFiberUser('first');
    $secondUser = new AuditFiberUser('second');
    $provider = new CurrentUserProvider($main);

    $first = new Fiber(function () use ($provider, $firstUser): array {
        expect($provider->getUser()?->name)->toBe('main');
        $provider->setUser($firstUser);
        $before = $provider->getUser();
        Fiber::suspend();
        $after = $provider->getUser();
        $provider->setUser(null);
        return [$before, $after, $provider->getUser()];
    });

    $second = new Fiber(function () use ($provider, $secondUser): array {
        expect($provider->getUser()?->name)->toBe('main');
        $provider->setUser($secondUser);
        $before = $provider->getUser();
        Fiber::suspend();
        $after = $provider->getUser();
        return [$before, $after];
    });

    $first->start();
    $second->start();

    expect($provider->getUser())->toBe($main);

    $second->resume();
    $first->resume();

    [$firstBefore, $firstAfter, $firstCleared] = $first->getReturn();
    [$secondBefore, $secondAfter] = $second->getReturn();

    expect($firstBefore)->toBe($firstUser)
        ->and($firstAfter)->toBe($firstUser)
        ->and($firstCleared)->toBeNull()
        ->and($secondBefore)->toBe($secondUser)
        ->and($secondAfter)->toBe($secondUser)
        ->and($provider->getUser())->toBe($main);
});
