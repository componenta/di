<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeCallbackInvocation;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use SensitiveParameter;
use WeakReference;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ReservedMetadata {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final readonly class Dependency
{
    public function __construct(public string $value = 'injected') {}
}

interface Unbound {}

final class SuffixStateResolver implements ParameterResolverInterface
{
    /** @var list<int> */
    public array $classified = [];

    public function supports(ParameterTarget $target): bool
    {
        if ($target->name === 'semantic') {
            throw new \LogicException('The semantic argument must never be classified.');
        }
        $this->classified[] = $target->position;
        return $target->name === 'suffixState';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, $context->resolved[0]];
    }
}

final class ObservedInvoker implements CallableInvokerInterface
{
    public int $calls = 0;
    public bool $prepared = false;
    /** @var array<string|int,mixed> */
    public array $arguments = [];

    public function __construct(private readonly CallableInvokerInterface $next) {}

    public function call(mixed $callable, array $params = []): mixed
    {
        ++$this->calls;
        $this->prepared = $callable instanceof PreparedCallable;
        $this->arguments = $params;
        return $this->next->call($callable, $params);
    }
}

test('semantic callback input bypasses resolver classification and remains visible to its suffix', function (bool $uncached): void {
    $probe = new SuffixStateResolver();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReservedMetadata::class, rules: $uncached ? [new NoOpRule()] : []))
        ->addParameterResolver($probe, 2000)
        ->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);
    $callback = static fn(int $semantic = 99, int $suffixState = 0): array => [$semantic, $suffixState];

    expect($callbacks->invoke($callback, 7, [0 => 80, 'semantic' => 90]))->toBe([7, 7])
        ->and($callbacks->invoke($callback, 8))->toBe([8, 8])
        ->and($probe->classified)->not->toContain(0);
})->with(['cached' => false, 'non-memoized' => true]);

test('semantic callbacks resolve full suffix signatures and current services', function (bool $uncached): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReservedMetadata::class, rules: $uncached ? [new NoOpRule()] : []))
        ->addService('callback.dependency', new Dependency('first'))
        ->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);
    $callback = static fn(
        array $whole,
        #[EntryId('callback.dependency')] Dependency $dependency,
        Dependency $autowired,
        ?Unbound $nullable,
        string $label = 'default',
        int ...$tail,
    ): array => [$whole, $dependency->value, $autowired->value, $nullable, $label, $tail];

    expect($callbacks->invoke($callback, ['key' => 1], ['tail' => [2, 'named' => 3]]))
        ->toBe([['key' => 1], 'first', 'injected', null, 'default', [2, 'named' => 3]]);
    $container->set('callback.dependency', new Dependency('second'));
    expect($callbacks->invoke($callback, [], [4 => 'position', 5 => 4]))
        ->toBe([[], 'second', 'injected', null, 'position', [4]]);
})->with(['cached' => false, 'non-memoized' => true]);

test('empty callbacks receive zero native arguments through one prepared decorator call', function (): void {
    $container = (new ContainerBuilder())->build();
    $observed = new ObservedInvoker($container);
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $observed);

    expect($callbacks->invoke(static fn(): int => func_num_args(), ['whole' => 'value'], ['unused' => 42]))->toBe(0)
        ->and($observed->calls)->toBe(1)
        ->and($observed->prepared)->toBeTrue()
        ->and($observed->arguments)->toBe([]);
});

test('semantic callbacks preserve native omitted defaults and a single prepared invocation', function (): void {
    $container = (new ContainerBuilder())->build();
    $observed = new ObservedInvoker($container);
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $observed);

    expect($callbacks->invoke(array_keys(...), ['nil' => null, 'one' => 1]))->toBe(['nil', 'one'])
        ->and($observed->calls)->toBe(1)
        ->and($observed->prepared)->toBeTrue()
        ->and($observed->arguments)->toBe([['nil' => null, 'one' => 1]])
        ->and(fn() => $callbacks->invoke(array_keys(...), ['nil' => null, 'one' => 1], ['strict' => true]))
        ->toThrow(\ArgumentCountError::class)
        ->and($observed->arguments)->toBe([['nil' => null, 'one' => 1], 'strict' => true]);
});

test('semantic callbacks allow ordinary metadata and optional nullable first arguments', function (): void {
    $container = (new ContainerBuilder())->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);

    expect($callbacks->invoke(static fn(#[SensitiveParameter] ?string $value = 'default'): ?string => $value, null))->toBeNull()
        ->and($callbacks->invoke(static fn(?string $arbitrary = 'default'): ?string => $arbitrary, 'actual'))->toBe('actual');
});

test('semantic callback first arguments reject incompatible types', function (): void {
    $container = (new ContainerBuilder())->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);

    expect(fn() => $callbacks->invoke(static fn(int $semantic): int => $semantic, '7'))
        ->toThrow(ResolutionException::class, '$semantic');
});

test('semantic callbacks reject unsupported first parameter ABI', function (Closure $callback): void {
    $container = (new ContainerBuilder())->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);

    expect(fn() => $callbacks->invoke($callback, 7))->toThrow(InvalidConfigurationException::class);
})->with([
    'reference' => [static function (int &$value): int {
        return $value;
    }],
    'variadic' => [static fn(int ...$values): array => $values],
]);

test('semantic callbacks reject registered first parameter DI metadata', function (Closure $callback): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReservedMetadata::class))
        ->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);

    expect(fn() => $callbacks->invoke($callback, 'actual'))->toThrow(InvalidConfigurationException::class);
})->with([
    'source' => [static fn(#[EntryId('never-resolved')] string $value): string => $value],
    'metadata only' => [static fn(#[ReservedMetadata] string $value): string => $value],
]);

test('semantic callbacks preserve target failures and release invocation values and closures', function (): void {
    $container = (new ContainerBuilder())->build();
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);
    $value = new \stdClass();
    $capture = new \stdClass();
    $callback = static fn(object $semantic, Dependency $dependency): array => [$semantic, $capture, $dependency->value];
    $references = array_map(WeakReference::create(...), [$value, $capture, $callback]);

    expect($callbacks->invoke($callback, $value))->toBe([$value, $capture, 'injected']);
    unset($value, $capture, $callback);
    gc_collect_cycles();
    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }

    $cause = new \DomainException('Callback failed.');
    try {
        $callbacks->invoke(static function (mixed $value) use ($cause): never {
            throw $cause;
        }, null);
        \PHPUnit\Framework\Assert::fail('The original callback failure must propagate.');
    } catch (\DomainException $actual) {
        expect($actual)->toBe($cause);
    }
});

#[Attribute(Attribute::TARGET_PARAMETER)]
class CompletedTransformer {}

#[Attribute(Attribute::TARGET_PARAMETER)]
class LateTransformer {}

final class CountedTransformer implements ParameterAttributeHandlerInterface
{
    public int $calls = 0;

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        ++$this->calls;
        return ParameterAttributeValue::resolved('transformed');
    }
}

final class InvocationLog
{
    public static int $calls = 0;
}

final class LoadingDependency {}

test('late DI metadata on the reserved callback argument rejects completed suffix work without replay', function (bool $uncached): void {
    $attribute = 'ReservedAfterSuffix_' . bin2hex(random_bytes(5));
    $counter = new CountedTransformer();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ReservedMetadata::class, rules: $uncached ? [new NoOpRule()] : []))
        ->addAttributeDefinition(new AttributeDefinition(CompletedTransformer::class, $counter, [ValueTransformer::class]))
        ->addFactory(LoadingDependency::class, static function () use ($attribute): LoadingDependency {
            eval(sprintf('namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s extends ReservedMetadata {}', __NAMESPACE__, $attribute));
            return new LoadingDependency();
        })
        ->build();
    $callback = eval(sprintf(
        'namespace %s; return static function (#[%s] object $semantic, #[CompletedTransformer] string $value, LoadingDependency $dependency): void { ++InvocationLog::$calls; };',
        __NAMESPACE__,
        $attribute,
    ));
    if (!$callback instanceof Closure) {
        throw new \LogicException('Expected the late-metadata callback fixture.');
    }
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);
    InvocationLog::$calls = 0;

    expect(fn() => $callbacks->invoke($callback, new \stdClass()))
        ->toThrow(AttributeCompositionException::class)
        ->and($counter->calls)->toBe(1)
        ->and(InvocationLog::$calls)->toBe(0);
})->with(['cached' => false, 'non-memoized' => true]);

test('reserved-target discovery cannot change an already completed callback suffix silently', function (bool $uncached): void {
    $id = bin2hex(random_bytes(5));
    $first = 'NeutralFirst_' . $id;
    $last = 'LateSuffix_' . $id;
    $counter = new CountedTransformer();
    $late = new CountedTransformer();
    $armed = false;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CompletedTransformer::class, $counter, [ValueTransformer::class], rules: $uncached ? [new NoOpRule()] : []))
        ->addAttributeDefinition(new AttributeDefinition(LateTransformer::class, $late, [ValueTransformer::class]))
        ->addFactory(LoadingDependency::class, static function () use (&$armed): LoadingDependency {
            $armed = true;
            return new LoadingDependency();
        })
        ->build();
    $callback = eval(sprintf(
        'namespace %s; return static function (#[%s] object $semantic, #[CompletedTransformer, %s] string $value, LoadingDependency $trigger): void { ++InvocationLog::$calls; };',
        __NAMESPACE__,
        $first,
        $last,
    ));
    if (!$callback instanceof Closure) {
        throw new \LogicException('Expected the completion-order callback fixture.');
    }
    $autoload = static function (string $class) use ($first, $last, &$armed): void {
        if ($class === __NAMESPACE__ . '\\' . $first && $armed) {
            eval(sprintf(
                'namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s {} #[\\Attribute(\\Attribute::TARGET_PARAMETER)] final class %s extends LateTransformer {}',
                __NAMESPACE__,
                $first,
                $last,
            ));
        }
    };
    spl_autoload_register($autoload);
    $callbacks = new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container);
    InvocationLog::$calls = 0;

    try {
        expect(fn() => $callbacks->invoke($callback, new \stdClass()))
            ->toThrow(AttributeCompositionException::class)
            ->and($counter->calls)->toBe(1)
            ->and($late->calls)->toBe(0)
            ->and(InvocationLog::$calls)->toBe(0);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['cached' => false, 'non-memoized' => true]);
