<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CentralDefinitionStrategy;

use Closure;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\MutationRetirementException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Fiber;
use WeakReference;

final readonly class ValueDefinition implements DefinitionInterface
{
    public function __construct(public mixed $value) {}
}

final readonly class ValidatedValue implements DefinitionInterface
{
    public function __construct(public mixed $value) {}
}

final class ValueStrategy implements DefinitionResolverInterface
{
    public int $supportsCalls = 0;
    public int $validationCalls = 0;
    public int $resolutionCalls = 0;
    /** @var Closure(string, ValueDefinition):void|null */
    public ?Closure $onValidate = null;
    /** @var Closure(ValidatedValue,array<string|int,mixed>):mixed|null */
    public ?Closure $onResolve = null;

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        ++$this->supportsCalls;
        return $definition instanceof ValueDefinition;
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        ++$this->validationCalls;
        if (!$definition instanceof ValueDefinition) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }
        ($this->onValidate)?->__invoke($id, $definition);
        return new ValidatedValue($definition->value);
    }

    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed
    {
        ++$this->resolutionCalls;
        if (!$validated instanceof ValidatedValue) {
            throw new \LogicException('The strategy must receive its validated descriptor.');
        }
        return $this->onResolve === null ? $validated->value : ($this->onResolve)($validated, $params);
    }
}

final class ValueFallback implements EntryResolverInterface
{
    public int $calls = 0;

    public function can(string $id): bool
    {
        ++$this->calls;
        return $id === 'entry';
    }

    public function resolve(string $id, array $params = []): mixed
    {
        return ['fallback', $params];
    }
}

final class RetirementState
{
    public int $calls = 0;
}

final readonly class RetiringValue
{
    public function __construct(private RetirementState $state) {}

    public function __destruct()
    {
        ++$this->state->calls;
        throw new \LogicException('Standalone retired value failed.');
    }
}

test('explicit definitions use the first supporting strategy and its validated payload ahead of fallbacks', function (): void {
    $first = new ValueStrategy();
    $later = new ValueStrategy();
    $fallback = new ValueFallback();
    $resolver = new CompositeResolver($fallback, $first, $later);
    $value = new \stdClass();

    $resolver->setDefinition('entry', new ValueDefinition($value));

    expect($resolver->can('entry'))->toBeTrue()
        ->and($resolver->resolve('entry'))->toBe($value)
        ->and($resolver->resolve('entry'))->toBe($value)
        ->and($first->validationCalls)->toBe(1)
        ->and($first->resolutionCalls)->toBe(2)
        ->and($later->supportsCalls)->toBe(0)
        ->and($later->validationCalls)->toBe(0)
        ->and($fallback->calls)->toBe(0);
});

test('definition rejection neither changes the current binding nor tries a later supporting strategy', function (): void {
    $first = new ValueStrategy();
    $later = new ValueStrategy();
    $resolver = new CompositeResolver($first, $later);
    $resolver->setDefinition('entry', new ValueDefinition('original'));
    $failure = new InvalidConfigurationException('The selected strategy rejected the definition.');
    $first->onValidate = static function () use ($failure): never {
        throw $failure;
    };

    expect(fn() => $resolver->setDefinition('entry', new ValueDefinition('rejected')))->toThrow($failure)
        ->and($resolver->resolve('entry'))->toBe('original')
        ->and($later->validationCalls)->toBe(0);
});

test('unsupported definitions preserve an existing binding and missing entries retain not-found semantics', function (): void {
    $resolver = new CompositeResolver(new ValueStrategy());
    $resolver->setDefinition('entry', new ValueDefinition(null));

    expect(fn() => $resolver->setDefinition('entry', new ValidatedValue('not fresh input')))
        ->toThrow(InvalidConfigurationException::class, 'is not supported')
        ->and($resolver->can('entry'))->toBeTrue()
        ->and($resolver->resolve('entry'))->toBeNull()
        ->and($resolver->can('missing'))->toBeFalse()
        ->and(fn() => $resolver->resolve('missing'))->toThrow(NotFoundException::class);
});

test('custom strategies and fallback resolvers receive only public caller parameters', function (bool $explicit): void {
    $strategy = new ValueStrategy();
    $strategy->onResolve = static fn(ValidatedValue $definition, array $params): array => $params;
    $resolver = new CompositeResolver($strategy, new ValueFallback());
    if ($explicit) {
        $resolver->setDefinition('entry', new ValueDefinition('configured'));
    }
    $params = ['name' => 'caller', 9 => 'position', "\0componenta.di.private" => new \stdClass()];
    $expected = ['name' => 'caller', 9 => 'position'];

    expect($resolver->resolve('entry', $params))->toBe($explicit ? $expected : ['fallback', $expected]);
})->with(['fallback' => false, 'definition' => true]);

test('validation reentry preserves nested writes when the outer definition is rejected or committed', function (bool $reject): void {
    $strategy = new ValueStrategy();
    $resolver = new CompositeResolver($strategy);
    $resolver->setDefinition('entry', new ValueDefinition('original'));
    $strategy->onValidate = static function (string $id, ValueDefinition $definition) use ($resolver, $reject): void {
        if ($definition->value !== 'outer') {
            return;
        }
        $resolver->setDefinition($id, new ValueDefinition('nested'));
        if ($reject) {
            throw new InvalidConfigurationException('Reject the outer definition.');
        }
    };

    if ($reject) {
        expect(fn() => $resolver->setDefinition('entry', new ValueDefinition('outer')))
            ->toThrow(InvalidConfigurationException::class, 'Reject the outer definition.');
    } else {
        $resolver->setDefinition('entry', new ValueDefinition('outer'));
    }

    expect($resolver->resolve('entry'))->toBe($reject ? 'nested' : 'outer');
})->with(['commit' => false, 'reject' => true]);

test('an in-flight definition retains its snapshot until suspended resolution completes', function (): void {
    $strategy = new ValueStrategy();
    $resolver = new CompositeResolver($strategy);
    $old = new \stdClass();
    $reference = WeakReference::create($old);
    $resolver->setDefinition('entry', new ValueDefinition($old));
    unset($old);
    $strategy->onResolve = static function (ValidatedValue $definition): string {
        Fiber::suspend('resolving');
        return is_object($definition->value) ? 'old' : 'unexpected';
    };
    $fiber = new Fiber(static fn(): mixed => $resolver->resolve('entry'));

    expect($fiber->start())->toBe('resolving');
    $resolver->setDefinition('entry', new ValueDefinition('new'));
    expect($reference->get())->not->toBeNull();
    $fiber->resume();
    $strategy->onResolve = null;

    expect($fiber->getReturn())->toBe('old')
        ->and($reference->get())->toBeNull()
        ->and($resolver->resolve('entry'))->toBe('new');
});

test('foreign validation failures are configuration errors without replacing a binding', function (): void {
    $strategy = new ValueStrategy();
    $resolver = new CompositeResolver($strategy);
    $resolver->setDefinition('entry', new ValueDefinition('original'));
    $failure = new \RuntimeException('Application validation failed.');
    $strategy->onValidate = static function () use ($failure): never {
        throw $failure;
    };

    try {
        $resolver->setDefinition('entry', new ValueDefinition('rejected'));
        throw new \LogicException('Expected the validation failure.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception->getPrevious())->toBe($failure)
            ->and($resolver->resolve('entry'))->toBe('original');
    }
});

test('standalone replacement reports retirement failure only after committing its new binding', function (): void {
    $state = new RetirementState();
    $resolver = new CompositeResolver(new ValueStrategy());
    $resolver->setDefinition('entry', new ValueDefinition(new RetiringValue($state)));

    try {
        $resolver->setDefinition('entry', new ValueDefinition('new'));
        throw new \LogicException('Expected the retired value to fail.');
    } catch (MutationRetirementException $exception) {
        expect($exception->id)->toBe('entry')
            ->and($exception->causes)->toHaveCount(1)
            ->and($exception->getPrevious()?->getMessage())->toBe('Standalone retired value failed.')
            ->and($state->calls)->toBe(1)
            ->and($resolver->resolve('entry'))->toBe('new');
    }
});

test('resuming validation retires the binding current at publication instead of a stale earlier snapshot', function (): void {
    $strategy = new ValueStrategy();
    $resolver = new CompositeResolver($strategy);
    $first = new \stdClass();
    $firstReference = WeakReference::create($first);
    $resolver->setDefinition('entry', new ValueDefinition($first));
    unset($first);
    $strategy->onValidate = static function (string $id, ValueDefinition $definition): void {
        if ($definition->value === 'outer') {
            Fiber::suspend('validating');
        }
    };
    $fiber = new Fiber(static fn() => $resolver->setDefinition('entry', new ValueDefinition('outer')));

    expect($fiber->start())->toBe('validating');
    $nested = new \stdClass();
    $nestedReference = WeakReference::create($nested);
    $resolver->setDefinition('entry', new ValueDefinition($nested));
    unset($nested);
    expect($firstReference->get())->toBeNull()
        ->and($nestedReference->get())->not->toBeNull();

    $fiber->resume();

    expect($resolver->resolve('entry'))->toBe('outer')
        ->and($nestedReference->get())->toBeNull();
});
