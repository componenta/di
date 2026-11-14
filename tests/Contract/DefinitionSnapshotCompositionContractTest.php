<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DefinitionSnapshotComposition;

use Componenta\DI\Container;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AliasResolver;
use Componenta\DI\Internal\MutationRetirement;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\DefinitionResolverInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use WeakReference;

final readonly class SnapshotDefinition implements DefinitionInterface
{
    public function __construct(public mixed $value) {}
}

final class SnapshotStrategy implements DefinitionResolverInterface
{
    public int $supportsCalls = 0;
    public int $validationCalls = 0;

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        ++$this->supportsCalls;
        return $definition instanceof SnapshotDefinition;
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        ++$this->validationCalls;
        return $definition;
    }

    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed
    {
        if (!$validated instanceof SnapshotDefinition) {
            throw new \LogicException('Expected a snapshot definition.');
        }
        return $validated->value;
    }
}

final class FallbackTrace
{
    /** @var list<string> */
    public array $entries = [];
}

final readonly class TracedFallback implements EntryResolverInterface
{
    public function __construct(private string $label, private FallbackTrace $trace) {}

    public function can(string $id): bool
    {
        $this->trace->entries[] = $this->label;
        return false;
    }

    public function resolve(string $id, array $params = []): mixed
    {
        throw new \LogicException('Unavailable fallbacks must not execute.');
    }
}

final class HybridProvider implements DefinitionResolverInterface, EntryResolverInterface
{
    public int $classifications = 0;
    public int $fallbackQueries = 0;

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        ++$this->classifications;
        return $definition instanceof SnapshotDefinition;
    }

    public function validateDefinition(string $id, DefinitionInterface $definition): DefinitionInterface
    {
        return $definition;
    }

    public function resolveDefinition(string $id, DefinitionInterface $validated, array $params = []): mixed
    {
        if (!$validated instanceof SnapshotDefinition) {
            throw new \LogicException('Expected a hybrid definition.');
        }
        return $validated->value;
    }

    public function can(string $id): bool
    {
        ++$this->fallbackQueries;
        return false;
    }

    public function resolve(string $id, array $params = []): mixed
    {
        throw new \LogicException('The unavailable hybrid fallback must not execute.');
    }
}

test('composition imports identical bindings once and deduplicates shared definition strategies', function (): void {
    $strategy = new SnapshotStrategy();
    $common = new CompositeResolver($strategy);
    $common->setDefinition('shared', new SnapshotDefinition('shared-value'));
    $left = new CompositeResolver($common);
    $right = new CompositeResolver($common);
    $composed = new CompositeResolver($left, $right);

    expect($composed->resolve('shared'))->toBe('shared-value')
        ->and($strategy->validationCalls)->toBe(1);
    $strategy->supportsCalls = 0;
    $composed->setDefinition('new', new SnapshotDefinition('new-value'));

    expect($strategy->supportsCalls)->toBe(1)
        ->and($strategy->validationCalls)->toBe(2)
        ->and($composed->resolve('new'))->toBe('new-value');
});

test('snapshot composition preserves every fallback occurrence in provider order', function (): void {
    $trace = new FallbackTrace();
    $shared = new TracedFallback('shared', $trace);
    $left = new CompositeResolver($shared, new TracedFallback('left', $trace));
    $right = new CompositeResolver($shared, new TracedFallback('right', $trace));
    $composed = new CompositeResolver($left, $right);

    expect($composed->can('missing'))->toBeFalse()
        ->and($trace->entries)->toBe(['shared', 'left', 'shared', 'right']);
});

test('shared hybrid providers retain fallback occurrences while deduplicating their strategy role', function (): void {
    $hybrid = new HybridProvider();
    $composed = new CompositeResolver(new CompositeResolver($hybrid), new CompositeResolver($hybrid));

    expect($composed->can('missing'))->toBeFalse()
        ->and($hybrid->fallbackQueries)->toBe(2);
    $composed->setDefinition('entry', new SnapshotDefinition('explicit'));

    expect($hybrid->classifications)->toBe(1)
        ->and($composed->resolve('entry'))->toBe('explicit')
        ->and($hybrid->fallbackQueries)->toBe(2);
});

test('different bindings for the same id conflict even when they contain the identical definition object', function (): void {
    $strategy = new SnapshotStrategy();
    $definition = new SnapshotDefinition('same-payload');
    $left = new CompositeResolver($strategy);
    $right = new CompositeResolver($strategy);
    $left->setDefinition('entry', $definition);
    $right->setDefinition('entry', $definition);

    expect(fn() => new CompositeResolver($left, $right))->toThrow(InvalidConfigurationException::class, 'entry')
        ->and($left->resolve('entry'))->toBe('same-payload')
        ->and($right->resolve('entry'))->toBe('same-payload');
});

test('source and composed snapshots mutate independently without retaining the source composite', function (): void {
    $strategy = new SnapshotStrategy();
    $child = new CompositeResolver($strategy);
    $child->setDefinition('entry', new SnapshotDefinition('original'));
    $reference = WeakReference::create($child);
    $composed = new CompositeResolver($child);

    $child->setDefinition('entry', new SnapshotDefinition('child-new'));
    expect($composed->resolve('entry'))->toBe('original');
    $composed->setDefinition('entry', new SnapshotDefinition('parent-new'));
    expect($child->resolve('entry'))->toBe('child-new');
    unset($child);

    expect($reference->get())->toBeNull()
        ->and($composed->resolve('entry'))->toBe('parent-new');
});

test('duplicate provider objects passed directly to a composition are rejected', function (bool $nested): void {
    $provider = $nested ? new CompositeResolver(new SnapshotStrategy()) : new SnapshotStrategy();

    expect(fn() => new CompositeResolver($provider, $provider))->toThrow(InvalidConfigurationException::class, 'more than once');
})->with(['strategy' => false, 'child composite' => true]);

test('an attached composite has one container mutation authority and cannot be imported as a child', function (): void {
    $strategy = new SnapshotStrategy();
    $root = new CompositeResolver($strategy);
    $root->setDefinition('entry', new SnapshotDefinition('original'));
    $executor = (new ContainerBuilder())->build();
    $container = new Container($root, new AliasResolver(), $executor);

    expect($container->get('entry'))->toBe('original')
        ->and(fn() => $root->setDefinition('entry', new SnapshotDefinition('bypass')))
        ->toThrow(InvalidConfigurationException::class)
        ->and($strategy->validationCalls)->toBe(1)
        ->and(fn() => new Container($root, new AliasResolver(), $executor))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => new CompositeResolver($root))->toThrow(InvalidConfigurationException::class)
        ->and($container->get('entry'))->toBe('original');

    $container->set('entry', new SnapshotDefinition('current'));

    expect($container->get('entry'))->toBe('current')
        ->and($root->resolve('entry'))->toBe('current');
});

test('prepared bindings cannot be published into another composite', function (): void {
    $strategy = new SnapshotStrategy();
    $first = new CompositeResolver($strategy);
    $second = new CompositeResolver($strategy);
    $binding = $first->prepareDefinition('entry', new SnapshotDefinition('foreign'));
    $retirement = new MutationRetirement('entry');

    expect(fn() => $second->publishDefinition($binding, $retirement))->toThrow(InvalidConfigurationException::class)
        ->and($second->can('entry'))->toBeFalse();

    $first->publishDefinition($binding, $retirement);
    $retirement->release();

    expect($first->resolve('entry'))->toBe('foreign');
});

test('attached publication rejects missing or forged capabilities before changing a binding', function (): void {
    $root = new CompositeResolver(new SnapshotStrategy());
    $root->setDefinition('entry', new SnapshotDefinition('original'));
    $container = new Container($root, new AliasResolver(), (new ContainerBuilder())->build());
    $binding = $root->prepareDefinition('entry', new SnapshotDefinition('forged'));

    expect(fn() => $root->publishDefinition($binding, new MutationRetirement('entry')))
        ->toThrow(InvalidConfigurationException::class)
        ->and(fn() => $root->publishDefinition($binding, new MutationRetirement('entry'), new \stdClass()))
        ->toThrow(InvalidConfigurationException::class)
        ->and($container->get('entry'))->toBe('original')
        ->and($root->resolve('entry'))->toBe('original');
});

test('invalid container bootstrap does not claim the standalone composite mutation authority', function (): void {
    $root = new CompositeResolver(new SnapshotStrategy());
    $executor = (new ContainerBuilder())->build();

    expect(fn() => new Container($root, new AliasResolver(), $executor, bootstrapServices: ['invalid' => new \stdClass()]))
        ->toThrow(InvalidConfigurationException::class);
    $root->setDefinition('entry', new SnapshotDefinition('after-rejection'));
    $container = new Container($root, new AliasResolver(), $executor);

    expect($container->get('entry'))->toBe('after-rejection');
});
