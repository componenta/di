<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ContextualType;

use Closure;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

class Ancestor {}

class Declaring extends Ancestor
{
    public function same(self $value): self
    {
        return $value;
    }

    public function ancestor(parent $value): parent
    {
        return $value;
    }

    public static function sameClosure(): Closure
    {
        return static fn(self $value): self => $value;
    }

    public static function ancestorClosure(): Closure
    {
        return static fn(parent $value): parent => $value;
    }

    public static function sameVariadic(): Closure
    {
        return static fn(self ...$values): array => $values;
    }

    public static function ancestorVariadic(): Closure
    {
        return static fn(parent ...$values): array => $values;
    }
}

final class Inheriting extends Declaring {}
final class Rebound extends Ancestor {}

class Injected extends Ancestor
{
    #[Inject]
    public self $same;

    #[Inject]
    public parent $ancestor;
}

final class InheritedInjection extends Injected {}

/** @return Closure */
function rebind(Closure $closure): Closure
{
    return $closure->bindTo(null, Rebound::class)
        ?? throw new \LogicException('Could not bind the contextual closure.');
}

/** @param class-string $type */
test('contextual parameter types preserve the declaring or rebound scope', function (callable $call, string $type, object $wrong): void {
    $expected = new $type();
    $container = (new ContainerBuilder())->addService($type, $expected)->build();

    expect($container->call($call))->toBe($expected);
    foreach (['value', 0, $type] as $key) {
        $explicit = new $type();
        expect($container->call($call, [$key => $explicit]))->toBe($explicit);
    }
    foreach (['value', 0] as $key) {
        expect(fn() => $container->call($call, [$key => $wrong]))->toThrow(ResolutionException::class);
    }
})->with([
    'inherited self method' => [[new Inheriting(), 'same'], Declaring::class, new Ancestor()],
    'inherited parent method' => [[new Inheriting(), 'ancestor'], Ancestor::class, new \stdClass()],
    'self method closure' => [new Inheriting()->same(...), Declaring::class, new Ancestor()],
    'parent method closure' => [new Inheriting()->ancestor(...), Ancestor::class, new \stdClass()],
    'self closure from child' => [Inheriting::sameClosure(), Declaring::class, new Ancestor()],
    'parent closure from child' => [Inheriting::ancestorClosure(), Ancestor::class, new \stdClass()],
    'self rebound closure' => [rebind(Declaring::sameClosure()), Rebound::class, new Declaring()],
    'parent rebound closure' => [rebind(Declaring::ancestorClosure()), Ancestor::class, new \stdClass()],
]);

/** @param class-string $type */
test('contextual variadics validate each value without implicit autowiring', function (Closure $call, string $type, object $wrong): void {
    $first = new $type();
    $second = new $type();
    $container = (new ContainerBuilder())->addService($type, $first)->build();

    expect($container->call($call))->toBe([])
        ->and($container->call($call, ['values' => [$first, $second]]))->toBe([$first, $second])
        ->and($container->call($call, [0 => $first, 1 => $second]))->toBe([$first, $second]);
    expect(fn() => $container->call($call, ['values' => [$first, $wrong]]))->toThrow(ResolutionException::class);
})->with([
    'self' => [Declaring::sameVariadic(), Declaring::class, new Ancestor()],
    'parent' => [Declaring::ancestorVariadic(), Ancestor::class, new \stdClass()],
    'rebound self' => [rebind(Declaring::sameVariadic()), Rebound::class, new Declaring()],
]);

test('inherited property injection resolves self and parent in their declaration scope', function (): void {
    $same = new Injected();
    $ancestor = new Ancestor();
    $container = (new ContainerBuilder())
        ->addService(Injected::class, $same)
        ->addService(Ancestor::class, $ancestor)
        ->build();

    $result = $container->make(InheritedInjection::class);

    expect($result)->toBeInstanceOf(InheritedInjection::class)
        ->and($result->same)->toBe($same)
        ->and($result->ancestor)->toBe($ancestor);
});
