<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BootstrapInFlightFinalization;

use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Fiber;
use LogicException;
use PHPUnit\Framework\Assert;
use stdClass;
use WeakReference;

use function Componenta\DI\Tests\Support\assembledContainer;
use function Componenta\DI\Tests\Support\container;

final class Seed
{
    public function __construct(public string $seed = 'seed') {}
}

final class InFlight
{
    public static int $constructions = 0;

    public function __construct(public string $value = 'fallback')
    {
        ++self::$constructions;
    }
}

interface Interceptable {}

final class LoadedMarker {}

final class State
{
    /** @var Fiber<mixed,mixed,mixed,mixed>|null */
    public ?Fiber $fiber = null;
    public int $earlyValues = 0;
    public int $lateValues = 0;
    public int $loads = 0;
    public bool $resumedAfterSeal = false;
    /** @var list<string> */
    public array $events = [];
}

final readonly class EarlyWinner implements ParameterResolverInterface
{
    public function __construct(private State $state) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        if (++$this->state->earlyValues === 1) {
            $this->state->events[] = 'early:suspend';
            Fiber::suspend('suspended');
            $this->state->events[] = 'early:resume';
        }
        return [$target->position, 'early'];
    }
}

final class Passive implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return null;
    }
}

final readonly class LaterInterceptor implements ParameterResolverInterface
{
    public function __construct(private string $marker, private bool $applicable, private State $state) {}

    public function supports(ParameterTarget $target): bool
    {
        if ($target->name === 'seed') {
            // Immutable classification can autoload without changing attributes.
            return is_a($this->marker, Interceptable::class, true);
        }
        return $target->name === 'value' && $this->applicable;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        ++$this->state->lateValues;
        return [$target->position, 'late'];
    }
}

test('bootstrap proof checks old resolver prefixes completed by a Fiber during final classification', function (bool $applicable): void {
    InFlight::$constructions = 0;
    $state = new State();
    $marker = __NAMESPACE__ . '\\PendingMarker_' . bin2hex(random_bytes(5));
    $runtime = new ResolutionRuntime();
    $loader = static function (string $requested) use ($marker, $state, $runtime): void {
        if ($requested !== $marker) {
            return;
        }
        // Fixture-only protection against a broken finalization loop.
        if (++$state->loads > 256) {
            throw new LogicException('Fixture stopped repeated finalization loads at 256.');
        }
        $fiber = $state->fiber ?? throw new LogicException('Expected the suspended Fiber.');
        $state->resumedAfterSeal = $runtime->parameters->isSealed;
        if (!$fiber->isSuspended()) {
            throw new LogicException('Expected a suspended parameter resolver.');
        }
        $state->events[] = 'loader:resume';
        $fiber->resume();
        class_alias(LoadedMarker::class, $requested);
    };
    spl_autoload_register($loader);

    try {
        $di = null;
        $failure = null;
        try {
            [$di] = assembledContainer([
                ConfigKey::PARAMETER_RESOLVERS => [
                    500 => new EarlyWinner($state),
                    100 => static function (Container $di) use ($state): ParameterResolverInterface {
                        $di->get(Seed::class);
                        $state->fiber = new Fiber(static fn(): mixed => $di->get(InFlight::class));
                        if ($state->fiber->start() !== 'suspended' || !$state->fiber->isSuspended()) {
                            throw new LogicException('Expected the early winner to suspend.');
                        }
                        $state->events[] = 'factory:return';
                        return new Passive();
                    },
                    1000 => new LaterInterceptor($marker, $applicable, $state),
                ],
            ], runtime: $runtime);
        } catch (InvalidConfigurationException $error) {
            $failure = $error;
        }

        if ($applicable) {
            expect($failure)->toBeInstanceOf(InvalidConfigurationException::class)
                ->and($failure?->getMessage())->toContain(InFlight::class, LaterInterceptor::class, EarlyWinner::class)
                ->and($di)->toBeNull();
        } else {
            expect($failure)->toBeNull()->and($di)->toBeInstanceOf(Container::class);
        }

        expect($state->events)->toBe(['early:suspend', 'factory:return', 'loader:resume', 'early:resume'])
            ->and($state->resumedAfterSeal)->toBeTrue()
            ->and($state->loads)->toBe(1)
            ->and($state->earlyValues)->toBe(1)
            ->and($state->lateValues)->toBe(0)
            ->and(InFlight::$constructions)->toBe(1)
            ->and($state->fiber?->isTerminated())->toBeTrue();

        $resolved = $state->fiber?->getReturn();
        if (!$resolved instanceof InFlight) {
            throw new LogicException('Expected the completed in-flight dependency.');
        }
        expect($resolved->value)->toBe('early');
        if (!$applicable && $di instanceof Container) {
            expect($di->get(InFlight::class))->toBe($resolved);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['applicable later interceptor' => true, 'unrelated later resolver' => false]);

test('a bootstrap fiber finishing after publication releases its closure captures', function (): void {
    $state = new State();
    $weak = null;
    $di = container([
        ConfigKey::PARAMETER_RESOLVERS => [
            500 => new EarlyWinner($state),
            100 => static function (Container $bootstrap) use ($state, &$weak): ParameterResolverInterface {
                $capture = new stdClass();
                $weak = WeakReference::create($capture);
                $call = static fn(string $value): string => $value . $capture::class;
                $state->fiber = new Fiber(static fn(): mixed => $bootstrap->call($call));
                if ($state->fiber->start() !== 'suspended') {
                    throw new LogicException('Expected the argument resolution to suspend.');
                }
                return new Passive();
            },
        ],
    ]);
    $fiber = $state->fiber ?? throw new LogicException('Expected an in-flight invocation.');
    expect($weak?->get())->toBeInstanceOf(stdClass::class);
    $fiber->resume();

    expect($fiber->getReturn())->toBe('early' . stdClass::class)
        ->and($weak?->get())->toBeNull()
        ->and($di->has(Container::class))->toBeTrue();
});

test('a bootstrap fiber resumed after the factory returns checks its original resolver prefix before construction', function (bool $applicable): void {
    InFlight::$constructions = 0;
    $state = new State();
    $di = container([
        ConfigKey::PARAMETER_RESOLVERS => [
            500 => new EarlyWinner($state),
            1000 => static function (Container $bootstrap) use ($state, $applicable): ParameterResolverInterface {
                $state->fiber = new Fiber(static fn(): mixed => $bootstrap->get(InFlight::class));
                if ($state->fiber->start() !== 'suspended') {
                    throw new LogicException('Expected the early resolver to suspend.');
                }
                return new LaterInterceptor(LoadedMarker::class, $applicable, $state);
            },
        ],
    ]);
    $fiber = $state->fiber ?? throw new LogicException('Expected an in-flight bootstrap resolution.');
    expect($fiber->isSuspended())->toBeTrue()
        ->and(InFlight::$constructions)->toBe(0);

    if ($applicable) {
        expect(fn() => $fiber->resume())->toThrow(InvalidConfigurationException::class, LaterInterceptor::class)
            ->and(InFlight::$constructions)->toBe(0);
        $value = $di->get(InFlight::class);
        Assert::assertInstanceOf(InFlight::class, $value);
        expect($value->value)->toBe('late')
            ->and(InFlight::$constructions)->toBe(1)
            ->and($state->lateValues)->toBe(1);
    } else {
        $fiber->resume();
        expect($di->get(InFlight::class))->toBe($fiber->getReturn())
            ->and($state->lateValues)->toBe(0);
    }
    expect($state->earlyValues)->toBe(1);
})->with(['applicable later interceptor' => true, 'unrelated later resolver' => false]);
