<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestSourceCastingContract;

use Attribute;
use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class State
{
    /** @var list<mixed> */
    public static array $values = [];
    public static int $factories = 0;
    public static int $lookups = 0;
    public static int $constructed = 0;
    public static int $invoked = 0;
}

final class Provider implements CasterProviderInterface, CasterInterface
{
    public string $name = 'role';

    public function __construct(private readonly Closure $reveal, private readonly bool $duringLookup) {}

    public function provide(string $name): CasterInterface
    {
        ++State::$lookups;
        if ($this->duringLookup) {
            ($this->reveal)();
        }
        return $this;
    }

    public function cast(mixed $value): mixed
    {
        State::$values[] = $value;
        return $value;
    }
}

abstract class Input
{
    public string $role;
}

beforeEach(function (): void {
    State::$values = [];
    State::$factories = 0;
    State::$lookups = 0;
    State::$constructed = 0;
    State::$invoked = 0;
});

test('caster discovery cannot pass mapped data to cast after revealing an explicit source', function (
    bool $duringLookup,
    bool $loadSource,
    bool $uncached,
): void {
    $suffix = bin2hex(random_bytes(5));
    $dto = 'Dto_' . $suffix;
    $source = 'Source_' . $suffix;
    $sourceClass = __NAMESPACE__ . '\\' . $source;
    eval(sprintf(
        'namespace %s; final class %s extends Input { public function __construct(#[%s("trusted"), \\Componenta\\DI\\Attribute\\Cast("role")] string $role) { $this->role = $role; ++State::$constructed; } }',
        __NAMESPACE__,
        $dto,
        $source,
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[\\Componenta\\DI\\Attribute\\MapQueryString] %s $dto): string { ++State::$invoked; return $dto->role; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected the request mapping callable.');
    }

    $reveal = static function () use ($sourceClass, $loadSource): void {
        if ($loadSource && !class_exists($sourceClass, false)) {
            class_alias(EntryId::class, $sourceClass);
        }
    };
    $provider = new Provider($reveal, $duringLookup);
    $builder = (new ContainerBuilder())
        ->addService('trusted', 'trusted value')
        ->addFactory(CasterProviderInterface::class, static function () use ($provider, $reveal, $duringLookup): CasterProviderInterface {
            ++State::$factories;
            if (!$duringLookup) {
                $reveal();
            }
            return $provider;
        });
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    $failure = null;
    $result = null;
    try {
        $result = $container->call($target, [ServerRequestInterface::class => $request]);
    } catch (Throwable $error) {
        $failure = $error;
    }

    expect(State::$factories)->toBe(1);
    if ($duringLookup || !$loadSource) {
        expect(State::$lookups)->toBe(1);
    }
    if ($loadSource) {
        expect(State::$values)->toBe([])
            ->and(State::$constructed)->toBe(0)
            ->and(State::$invoked)->toBe(0)
            ->and($failure)->toBeInstanceOf(RequestParameterSourceConflictException::class);

        // A conflict does not poison a later call that supplies no HTTP shadow.
        expect($container->call($target, [ServerRequestInterface::class => $request->withQueryParams([])]))
            ->toBe('trusted value')
            ->and(State::$values)->toBe(['trusted value'])
            ->and(State::$constructed)->toBe(1)
            ->and(State::$invoked)->toBe(1);
    } else {
        expect($failure)->toBeNull()
            ->and($result)->toBe('untrusted')
            ->and(State::$values)->toBe(['untrusted'])
            ->and(State::$constructed)->toBe(1)
            ->and(State::$invoked)->toBe(1);
    }
})->with(['provider factory' => false, 'provider lookup' => true])
    ->with(['late source' => true, 'unavailable metadata' => false])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);
