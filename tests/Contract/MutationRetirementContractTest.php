<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\MutationRetirement;

use Closure;
use Componenta\DI\Container;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\MutationRetirementException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;
use WeakReference;

final readonly class Product
{
    public function __construct(public string $label) {}
}

final class RetiringFactory
{
    public function __construct(public readonly object $payload, private readonly ?Closure $retire = null) {}

    public function __invoke(): Product
    {
        return new Product('old');
    }

    public function __destruct()
    {
        ($this->retire ?? static function (): void {})();
    }
}

final class Wrapper
{
    public static ?Closure $retire = null;

    public function __construct(public readonly Product $source, public readonly string $name) {}

    public function __destruct()
    {
        if (self::$retire !== null) {
            (self::$retire)($this);
        }
    }
}

function wrapper(Container $container, string $id): Wrapper
{
    $entry = $container->get($id);
    if (!$entry instanceof Wrapper) {
        throw new LogicException('Expected a decorated wrapper.');
    }
    return $entry;
}

/** @return Throwable|null */
function mutationFailure(Closure $operation): ?Throwable
{
    try {
        $operation();
        return null;
    } catch (InvalidConfigurationException $error) {
        return $error;
    }
}

test('replacing a definition strategy releases obsolete factory configuration while the container stays alive', function (): void {
    $payload = new \stdClass();
    $factory = new RetiringFactory($payload);
    $factoryReference = WeakReference::create($factory);
    $payloadReference = WeakReference::create($payload);
    $container = (new ContainerBuilder())->addFactory('product', $factory)->build();
    unset($factory, $payload);
    expect($container->get('product'))->toEqual(new Product('old'));

    $container->set('product', Definition::invokable(\stdClass::class));

    expect($container->make('product'))->toBeInstanceOf(\stdClass::class)
        ->and($factoryReference->get())->toBeNull()
        ->and($payloadReference->get())->toBeNull();
});

test('a retired factory failure reports a committed definition after shared and fresh paths agree', function (): void {
    $cause = new LogicException('Old factory released.');
    $container = (new ContainerBuilder())->addFactory('product', new RetiringFactory(
        new \stdClass(),
        static function () use ($cause): never {
            throw $cause;
        },
    ))->build();
    $old = $container->get('product');

    $error = mutationFailure(fn() => $container->set('product', Definition::factory(
        static fn(): Product => new Product('new'),
    )));

    expect($container->get('product'))->toEqual(new Product('new'))
        ->and($container->get('product') === $old)->toBeFalse()
        ->and($container->make('product'))->toEqual(new Product('new'))
        ->and($error)->toBeInstanceOf(MutationRetirementException::class);
    if (!$error instanceof MutationRetirementException) {
        throw new LogicException('Expected committed retirement failure.');
    }
    expect($error->id)->toBe('product')
        ->and($error->causes)->toBe([$cause])
        ->and($error->getPrevious())->toBe($cause)
        ->and($error->getMessage())->toContain('committed');
});

test('throwing cache wrapper retirement cannot leave a sibling alias with its old result', function (): void {
    $container = (new ContainerBuilder())
        ->addService('source', new Product('old'))
        ->addAlias('a', 'source')
        ->addAlias('b', 'source')
        ->addDelegator('a', static fn(Product $source): Wrapper => new Wrapper($source, 'a'))
        ->addDelegator('b', static fn(Product $source): Wrapper => new Wrapper($source, 'b'))
        ->build();
    expect(wrapper($container, 'a')->source->label)->toBe('old')
        ->and(wrapper($container, 'b')->source->label)->toBe('old');
    $cause = new LogicException('Old wrapper released.');
    Wrapper::$retire = static function (Wrapper $wrapper) use ($cause): void {
        if ($wrapper->name === 'a' && $wrapper->source->label === 'old') {
            throw $cause;
        }
    };

    try {
        $error = null;
        try {
            $container->set('source', new Product('new'));
        } catch (Throwable $failure) {
            $error = $failure;
        }
        expect($container->get('source'))->toEqual(new Product('new'))
            ->and(wrapper($container, 'a')->source->label)->toBe('new')
            ->and(wrapper($container, 'b')->source->label)->toBe('new')
            ->and($error)->toBeInstanceOf(MutationRetirementException::class);
        if (!$error instanceof MutationRetirementException) {
            throw new LogicException('Expected committed cache retirement failure.');
        }
        expect($error->causes)->toBe([$cause]);
    } finally {
        Wrapper::$retire = null;
    }
});

final readonly class LabelDecorator
{
    public function __construct(public string $label) {}

    public function __invoke(Product $source): Wrapper
    {
        return new Wrapper(new Product($this->label), 'dependent');
    }
}

final readonly class ExternalDecorator implements ContainerInterface
{
    public function __construct(private LabelDecorator $decorator) {}

    public function has(string $id): bool
    {
        return $id === 'decorator';
    }

    public function get(string $id): mixed
    {
        if ($id !== 'decorator') {
            throw new LogicException('Unexpected external lookup.');
        }
        return $this->decorator;
    }
}

/** @return array{Container,Closure():void,?string} */
function mutationScenario(string $kind): array
{
    $builder = (new ContainerBuilder())
        ->addService('source', new Product('old'))
        ->addService('next', new Product('new'))
        ->addService('unrelated', new \stdClass())
        ->addAlias('parent', 'source')
        ->addAlias('a', 'parent')
        ->addAlias('b', 'parent');
    foreach (['a', 'b'] as $id) {
        $builder->addDelegator($id, in_array($kind, ['delegator', 'external'], true)
            ? 'decorator'
            : static fn(Product $source): Wrapper => new Wrapper($source, $id));
    }
    $container = $builder->addService('decorator', new LabelDecorator('old'))->build();
    return match ($kind) {
        'set' => [$container, fn() => $container->set('source', new Product('new')), 'source'],
        'alias' => [$container, fn() => $container->alias('parent', 'next'), 'parent'],
        'delegator' => [$container, fn() => $container->delegator('decorator', static fn(): LabelDecorator => new LabelDecorator('new')), 'decorator'],
        'external' => [$container, fn() => $container->addContainer(new ExternalDecorator(new LabelDecorator('new'))), null],
        default => throw new LogicException('Unknown mutation fixture.'),
    };
}

test('every registration mutation completes invalidation before retired wrappers can reenter lookup', function (string $kind): void {
    [$container, $mutate, $id] = mutationScenario($kind);
    $unrelated = $container->get('unrelated');
    expect(wrapper($container, 'a')->source->label)->toBe('old')
        ->and(wrapper($container, 'b')->source->label)->toBe('old');
    $observed = [];
    $cause = new LogicException('Retirement reentered the complete new state.');
    Wrapper::$retire = static function (Wrapper $wrapper) use ($container, &$observed, $cause): void {
        if ($wrapper->source->label !== 'old' || $observed !== []) {
            return;
        }
        $observed = [wrapper($container, 'a'), wrapper($container, 'b')];
        throw $cause;
    };

    try {
        $error = mutationFailure($mutate);
        expect($error)->toBeInstanceOf(MutationRetirementException::class)
            ->and($observed)->toHaveCount(2)
            ->and($observed[0]->source->label)->toBe('new')
            ->and($observed[1]->source->label)->toBe('new')
            ->and(wrapper($container, 'a'))->toBe($observed[0])
            ->and(wrapper($container, 'b'))->toBe($observed[1])
            ->and($container->get('unrelated'))->toBe($unrelated);
        if (!$error instanceof MutationRetirementException) {
            throw new LogicException('Expected committed mutation.');
        }
        expect($error->id)->toBe($id)->and($error->causes)->toBe([$cause]);
    } finally {
        Wrapper::$retire = null;
    }
})->with(['set', 'alias', 'delegator', 'external']);

test('a nested mutation from retirement remains current after the outer failure', function (): void {
    [$container, $mutate] = mutationScenario('set');
    wrapper($container, 'a');
    wrapper($container, 'b');
    $cause = new LogicException('Retired wrapper changed the source again.');
    $entered = false;
    Wrapper::$retire = static function (Wrapper $wrapper) use ($container, &$entered, $cause): void {
        if ($wrapper->source->label !== 'old' || $entered) {
            return;
        }
        $entered = true;
        expect(wrapper($container, 'a')->source->label)->toBe('new')
            ->and(wrapper($container, 'b')->source->label)->toBe('new');
        $container->set('source', new Product('nested'));
        throw $cause;
    };
    try {
        $error = mutationFailure($mutate);
        expect($error)->toBeInstanceOf(MutationRetirementException::class)
            ->and($entered)->toBeTrue()
            ->and(wrapper($container, 'a')->source->label)->toBe('nested')
            ->and(wrapper($container, 'b')->source->label)->toBe('nested');
    } finally {
        Wrapper::$retire = null;
    }
});

test('suspended retirement performs no later writes over a newer mutation', function (): void {
    [$container, $mutate] = mutationScenario('set');
    wrapper($container, 'a');
    wrapper($container, 'b');
    $cause = new LogicException('Retirement resumed.');
    $entered = false;
    Wrapper::$retire = static function (Wrapper $wrapper) use (&$entered, $cause): void {
        if ($wrapper->source->label !== 'old' || $entered) {
            return;
        }
        $entered = true;
        Fiber::suspend('retiring');
        throw $cause;
    };
    $error = null;
    $fiber = new Fiber(static function () use ($mutate, &$error): void {
        $error = mutationFailure($mutate);
    });
    try {
        expect($fiber->start())->toBe('retiring')
            ->and(wrapper($container, 'a')->source->label)->toBe('new')
            ->and(wrapper($container, 'b')->source->label)->toBe('new');
        $container->set('source', new Product('newest'));
        $latest = [wrapper($container, 'a'), wrapper($container, 'b')];
        $fiber->resume();

        expect($fiber->isTerminated())->toBeTrue()
            ->and($error)->toBeInstanceOf(MutationRetirementException::class)
            ->and($latest[0]->source->label)->toBe('newest')
            ->and($latest[1]->source->label)->toBe('newest')
            ->and(wrapper($container, 'a'))->toBe($latest[0])
            ->and(wrapper($container, 'b'))->toBe($latest[1]);
    } finally {
        Wrapper::$retire = null;
        if ($fiber->isSuspended()) {
            $fiber->resume();
        }
    }
});

test('raw shared replacement retires its old value while preserving the fresh definition and caller references', function (): void {
    $container = (new ContainerBuilder())->addFactory('product', static fn(): Product => new Product('factory'))->build();
    $application = new Product('application');
    $reference = WeakReference::create($application);
    $container->set('product', $application);
    $container->get('product');

    $container->set('product', new Product('replacement'));

    expect($container->get('product'))->toEqual(new Product('replacement'))
        ->and($container->make('product'))->toEqual(new Product('factory'))
        ->and($reference->get())->toBe($application);
    unset($application);
    expect($reference->get())->toBeNull();
});
