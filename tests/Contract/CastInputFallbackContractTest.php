<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CastInputFallback;

use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use RuntimeException;
use Throwable;

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

final class RecoverableCaster implements CasterInterface, CasterProviderInterface
{
    public ?Throwable $failure = null;
    public int $providerCalls = 0;
    public int $castCalls = 0;
    public string $name { get => 'recoverable'; }

    public function __construct(private readonly bool $providerFailure) {}

    public function provide(string $name): ?CasterInterface
    {
        if ($name !== $this->name) {
            return null;
        }
        ++$this->providerCalls;
        if ($this->providerFailure && $this->failure !== null) {
            throw $this->failure;
        }
        return $this;
    }

    public function cast(mixed $value): string
    {
        ++$this->castCalls;
        if (!$this->providerFailure && $this->failure !== null) {
            throw $this->failure;
        }
        if (!is_string($value)) {
            throw new LogicException('Expected the supplied caster input.');
        }
        return trim($value);
    }
}

final class CastRecoveryState
{
    public int $constructions = 0;
    public int $writes = 0;
    public int $bodyCalls = 0;
}

final class CastRecoveryProduct
{
    public function __construct(private CastRecoveryState $state)
    {
        ++$state->constructions;
    }

    #[EntryId('cast-recovery-input'), Cast('recoverable')]
    public string $value = 'unchanged' {
        set {
            ++$this->state->writes;
            $this->value = $value;
        }
    }
}

test('Cast provider and caster failures preserve diagnostics and allow a later successful resolution', function (
    bool $property,
    bool $providerFailure,
    bool $owned,
): void {
    $state = new CastRecoveryState();
    $caster = new RecoverableCaster($providerFailure);
    $cause = $owned ? new ResolutionException('owned cast failure') : new RuntimeException('foreign cast failure');
    $caster->failure = $cause;
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, $caster)
        ->addService(CastRecoveryState::class, $state)
        ->addService('cast-recovery-input', '  initial  ')
        ->build();
    $consumer = static function (#[Cast('recoverable')] string $value) use ($state): string {
        ++$state->bodyCalls;
        return $value;
    };
    $failure = null;
    try {
        if ($property) {
            $container->get(CastRecoveryProduct::class);
        } else {
            $container->call($consumer, ['value' => '  initial  ']);
        }
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ResolutionException::class);
    if (!$failure instanceof ResolutionException) {
        throw new LogicException('Expected the cast failure.');
    }
    if ($owned) {
        expect($failure)->toBe($cause)->and($failure->getPrevious())->toBeNull();
    } else {
        expect($failure->getPrevious())->toBe($cause);
        if ($property) {
            expect($failure->propertyClass)->toBe(CastRecoveryProduct::class)
                ->and($failure->propertyName)->toBe('value')
                ->and($failure->propertyType)->toBe('string');
        } else {
            expect($failure->parameterName)->toBe('value')
                ->and($failure->parameterPosition)->toBe(0)
                ->and($failure->providedParameterTypes)->toBe(['value' => 'string']);
        }
    }
    expect($caster->providerCalls)->toBe(1)
        ->and($caster->castCalls)->toBe($providerFailure ? 0 : 1)
        ->and($state->bodyCalls)->toBe(0)
        ->and($state->constructions)->toBe($property ? 1 : 0)
        ->and($state->writes)->toBe(0);

    $caster->failure = null;
    if ($property) {
        $container->set('cast-recovery-input', '  recovered  ');
        $product = $container->get(CastRecoveryProduct::class);
        expect($product->value)->toBe('recovered')
            ->and($container->get(CastRecoveryProduct::class))->toBe($product)
            ->and($state->constructions)->toBe(2)
            ->and($state->writes)->toBe(1);
    } else {
        expect($container->call($consumer, ['value' => '  recovered  ']))->toBe('recovered')
            ->and($state->bodyCalls)->toBe(1);
    }
    expect($caster->providerCalls)->toBe(2)->and($caster->castCalls)->toBe($providerFailure ? 1 : 2);
})->with([
    'parameter provider failure' => [false, true, false],
    'parameter owned caster failure' => [false, false, true],
    'property owned provider failure' => [true, true, true],
    'property caster failure' => [true, false, false],
]);
