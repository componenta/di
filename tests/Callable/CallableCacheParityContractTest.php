<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableCacheParityContract;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Config as ConfigValue;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\ResolutionRuntime;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionFunction;
use Throwable;
use WeakReference;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class CacheControl {}

final class NoOpCompositionRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class ClassificationProbe implements ParameterResolverInterface
{
    /** @var array<string,array<int,int>> */
    public array $calls = [];

    public function supports(ParameterTarget $target): bool
    {
        $function = $target->reflection->getDeclaringFunction()->getName();
        $this->calls[$function][$target->position] = ($this->calls[$function][$target->position] ?? 0) + 1;
        return false;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        throw new \LogicException('The observation-only resolver must never resolve a parameter.');
    }
}

final readonly class Dependency
{
    public function __construct(public string $value) {}
}

interface Unbound {}

final class DefaultToken
{
    public static int $created = 0;
    public readonly int $number;

    public function __construct()
    {
        $this->number = ++self::$created;
    }
}

/** @return array<string,mixed> */
function signature(
    #[CacheControl] Dependency $dependency,
    #[CacheControl, ConfigValue('label')] string $source,
    #[CacheControl] int $explicit,
    #[CacheControl] ?Unbound $nullable = null,
    #[CacheControl] object $default = new DefaultToken(),
    #[CacheControl] string ...$tail,
): array {
    return [
        'dependency' => $dependency->value,
        'source' => $source,
        'explicit' => $explicit,
        'nullable' => $nullable,
        'default' => $default instanceof DefaultToken ? $default->number : $default,
        'tail' => $tail,
    ];
}

class MethodOwner
{
    public function __construct(public readonly string $id) {}

    /** @return array{string,string,string,array<array-key,int>} */
    public function read(#[CacheControl] Dependency $dependency, string $label = 'native', int ...$values): array
    {
        return [$this->id, $dependency->value, $label, $values];
    }

    /** @return array{string,string,string,array<array-key,int>} */
    public function __invoke(#[CacheControl] Dependency $dependency, string $label = 'invoke', int ...$values): array
    {
        return $this->read($dependency, $label, ...$values);
    }

    /** @return array{string,int} */
    public static function staticRead(#[CacheControl] Dependency $dependency, int $number = 9): array
    {
        return [$dependency->value, $number];
    }
}

final class InheritedOwner extends MethodOwner {}

final class OverrideOwner extends MethodOwner
{
    /** @return array{string,string,string,array<array-key,int>} */
    public function read(#[CacheControl] Dependency $dependency, string $renamed = 'override', int ...$values): array
    {
        return parent::read($dependency, $renamed, ...$values);
    }
}

class TypeAncestor {}

class TypeScope extends TypeAncestor
{
    /** @return array{class-string,class-string} */
    public function contextual(#[CacheControl] self $value, parent $ancestor): array
    {
        return [$value::class, $ancestor::class];
    }

    public static function callback(): Closure
    {
        return static fn(self $value, parent $ancestor): array => [$value::class, $ancestor::class];
    }
}

final class TypeChild extends TypeScope {}
final class ReboundScope extends TypeAncestor {}

/** @method static array<array-key,mixed> missing(mixed ...$arguments) */
final class MagicOwner
{
    public function __construct(public readonly string $id) {}

    /** @return array{string,string} */
    private function hidden(Dependency $dependency): array
    {
        return [$this->id, $dependency->value];
    }

    public function privateCallback(): Closure
    {
        return $this->hidden(...);
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,string,array<array-key,mixed>}
     */
    public function __call(string $name, array $arguments): array
    {
        return [$this->id, $name, $arguments];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public static function __callStatic(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}

final class MutableResolution implements ParameterResolverInterface
{
    public ?string $current = null;

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'custom';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return $this->current === null ? null : [$target->position, $this->current];
    }
}

function customValue(#[CacheControl] string $custom = 'default'): string
{
    return $custom;
}

/** @return array{Container,ClassificationProbe,ResolutionRuntime} */
function auditContainer(bool $withRule = false, ?MutableResolution $mutable = null): array
{
    $probe = new ClassificationProbe();
    $builder = (new ContainerBuilder(new Config(['label' => 'configured'], new Environment([]))))
        ->addService(Dependency::class, new Dependency('first'))
        ->addAttributeDefinition(new AttributeDefinition(
            CacheControl::class,
            rules: $withRule ? [new NoOpCompositionRule()] : [],
        ))
        ->addParameterResolver($probe, -1000);
    if ($mutable !== null) {
        $builder->addParameterResolver($mutable, 2000);
    }
    [$container, $runtime] = $builder->assemble();
    return [$container, $probe, $runtime];
}

/** @return array{class-string<Throwable>,string,class-string<Throwable>|null} */
function failure(Closure $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [$exception::class, $exception->getMessage(), $exception->getPrevious() === null ? null : $exception->getPrevious()::class];
    }
    throw new \LogicException('The invalid call must fail.');
}

test('cache parity control reclassifies every parameter and preserves the resolved arguments', function (): void {
    $function = __NAMESPACE__ . '\\signature';
    $results = [];
    $fixedDefault = new \stdClass();
    foreach ([false, true] as $uncached) {
        DefaultToken::$created = 0;
        [$container, $probe] = auditContainer($uncached);
        $result = $container->call($function, ['explicit' => 1, 'default' => $fixedDefault]);
        expect($result)->toBe(['dependency' => 'first', 'source' => 'configured', 'explicit' => 1, 'nullable' => null, 'default' => $fixedDefault, 'tail' => []]);
        $results[] = $result;
        $first = $probe->calls[$function];
        $next = $container->call($function, ['explicit' => 2]);
        expect($next)->toBe(['dependency' => 'first', 'source' => 'configured', 'explicit' => 2, 'nullable' => null, 'default' => 1, 'tail' => []]);
        $second = $probe->calls[$function];

        expect(array_keys($first))->toBe([0, 1, 2, 3, 4, 5]);
        foreach ($first as $position => $count) {
            expect($uncached ? $second[$position] > $count : $second[$position] === $count)->toBeTrue();
        }
    }
    expect($results[0])->toBe($results[1]);
});

test('DI calls and explicitly resolved arguments preserve defaults null variadics and current services', function (bool $direct): void {
    $reports = [];
    foreach ([false, true] as $uncached) {
        DefaultToken::$created = 0;
        [$container, , $runtime] = auditContainer($uncached);
        $resolver = $runtime->parameters;
        $function = __NAMESPACE__ . '\\signature';
        $parameters = new ReflectionFunction($function)->getParameters();
        $call = static function (array $provided) use ($container, $resolver, $parameters, $function, $direct): array {
            $result = $direct
                ? $container->call($function, $provided)
                : $container->call(new PreparedCallable($function), $resolver->resolve($parameters, $provided));
            if (!is_array($result)) {
                throw new \LogicException('The complete signature must return its argument snapshot.');
            }
            return $result;
        };
        $report = [];
        $report[] = $call(['explicit' => 1, 'tail' => ['first', 'named' => 'second']]);
        $container->set(Dependency::class, new Dependency('second'));
        $report[] = $call([2 => 2, 'source' => 'explicit', 'nullable' => null, 5 => 'tail']);
        $report[] = $call(['explicit' => 3, 'tail' => []]);
        $report[] = failure(static fn(): mixed => $call(['explicit' => 'wrong']));
        $report[] = failure(static fn(): mixed => $call(['explicit' => 4, 'tail' => ['source' => 'conflict']]));
        $reports[] = $report;
        expect($report[0])->toBe(['dependency' => 'first', 'source' => 'configured', 'explicit' => 1, 'nullable' => null, 'default' => 1, 'tail' => ['first', 'named' => 'second']])
            ->and($report[1])->toBe(['dependency' => 'second', 'source' => 'explicit', 'explicit' => 2, 'nullable' => null, 'default' => 2, 'tail' => ['tail']])
            ->and($report[2])->toBe(['dependency' => 'second', 'source' => 'configured', 'explicit' => 3, 'nullable' => null, 'default' => 3, 'tail' => []])
            ->and($report[3][0])->toBe(ResolutionException::class)
            ->and($report[3][1])->toContain('$explicit')
            ->and($report[4][0])->toBe(ResolutionException::class)
            ->and($report[4][1])->toContain('variadic argument "source" would overwrite a preceding parameter');
    }
    expect($reports[0])->toBe($reports[1]);
})->with(['explicitly resolved arguments' => false, 'DI call' => true]);

test('reused and fresh containers preserve binding and complete native signatures', function (): void {
    [$reused] = auditContainer();
    $first = new MethodOwner('first-object');
    $second = new MethodOwner('second-object');
    $inherited = new InheritedOwner('inherited');
    $override = new OverrideOwner('override');
    $magic = new MagicOwner('magic');
    $captured = static fn(string $value = 'captured'): array => [$second->id, $value];
    $calls = [
        [[$first, 'read'], ['label' => 'one', 'values' => [1, 'named' => 2]]],
        [[$second, 'READ'], ['label' => 'two', 2 => 3]],
        [[$inherited, 'read'], []],
        [[$override, 'read'], ['renamed' => 'renamed', 'label' => 'ignored']],
        [$first, ['values' => [4]]],
        [$second, []],
        [$first->read(...), ['values' => [5]]],
        [[$second->read(...), '__INVOKE'], ['values' => [6]]],
        [$captured, []],
        [[$captured, '__invoke'], ['value' => 'method']],
        [MethodOwner::class . '::staticRead', ['number' => 7]],
        [[MethodOwner::class, 'STATICREAD'], []],
        [MethodOwner::staticRead(...), []],
        [[$magic, 'hidden'], [true, 'named' => 8]],
        [[$magic, 'missing'], ['named' => 9]],
        [$magic->missing(...), [10, 'named' => 11]],
        [$magic->privateCallback(), []],
        [MagicOwner::class . '::missing', [12, 'named' => 13]],
        [[MagicOwner::class, 'missing'], [14]],
        [MagicOwner::missing(...), [15]],
        ['array_keys', [['one' => 1, 'nil' => null]]],
        [array_keys(...), [['one' => 1, 'nil' => null], null]],
        ['array_slice', ['array' => ['a', 'b', 'c'], 'offset' => 1, 'preserve_keys' => true]],
        [static fn(): string => 'empty', ['unused' => 'ignored']],
    ];

    foreach (['first', 'second'] as $dependency) {
        $service = new Dependency($dependency);
        $reused->set(Dependency::class, $service);
        $reusedResults = [];
        $freshResults = [];
        foreach ($calls as [$callable, $provided]) {
            [$fresh] = auditContainer();
            $fresh->set(Dependency::class, $service);
            $reusedResults[] = $reused->call($callable, $provided);
            $freshResults[] = $fresh->call($callable, $provided);
        }

        expect($reusedResults)->toBe([
            ['first-object', $dependency, 'one', [1, 'named' => 2]],
            ['second-object', $dependency, 'two', [3]],
            ['inherited', $dependency, 'native', []],
            ['override', $dependency, 'renamed', []],
            ['first-object', $dependency, 'invoke', [4]],
            ['second-object', $dependency, 'invoke', []],
            ['first-object', $dependency, 'native', [5]],
            ['second-object', $dependency, 'native', [6]],
            ['second-object', 'captured'],
            ['second-object', 'method'],
            [$dependency, 7],
            [$dependency, 9],
            [$dependency, 9],
            ['magic', 'hidden', [true, 'named' => 8]],
            ['magic', 'missing', ['named' => 9]],
            ['magic', 'missing', [10, 'named' => 11]],
            ['magic', $dependency],
            ['missing', [12, 'named' => 13]],
            ['missing', [14]],
            ['missing', [15]],
            ['one', 'nil'],
            ['nil'],
            [1 => 'b', 2 => 'c'],
            'empty',
        ])->and($freshResults)->toBe($reusedResults);
    }
});

test('warm classification does not cache mutable resolver results or null continuation', function (): void {
    $reports = [];
    foreach ([false, true] as $uncached) {
        $resolver = new MutableResolution();
        [$container] = auditContainer($uncached, $resolver);
        $report = [];
        foreach ([null, 'first', 'second', null] as $value) {
            $resolver->current = $value;
            $report[] = $container->call(__NAMESPACE__ . '\\customValue');
        }
        expect($report)->toBe(['default', 'first', 'second', 'default']);
        $reports[] = $report;
    }
    expect($reports[0])->toBe($reports[1]);
});

test('warmed callable plans preserve native name casing and current service alias precedence', function (): void {
    $alias = __NAMESPACE__ . '\\OwnerAlias';
    if (!class_exists($alias, false)) {
        class_alias(MethodOwner::class, $alias);
    }
    $reports = [];
    foreach ([false, true] as $uncached) {
        [$container] = auditContainer($uncached);
        $function = __NAMESPACE__ . '\\customValue';
        $report = [];
        foreach ([$function, strtoupper($function), '\\' . $function] as $spelling) {
            $report[] = $container->call($spelling);
            $report[] = $container->call($spelling, ['custom' => 'explicit']);
        }
        foreach ([$alias . '::staticRead', strtoupper($alias) . '::STATICREAD', '\\' . $alias . '::staticRead'] as $spelling) {
            $report[] = $container->call($spelling);
        }
        $container->set('left-callable', new MethodOwner('left'));
        $container->set('right-callable', new OverrideOwner('right'));
        $container->alias('selected-callable', 'left-callable');
        $report[] = $container->call(['selected-callable', 'read'], ['label' => 'left-label']);
        $container->alias('selected-callable', 'right-callable');
        $report[] = $container->call(['selected-callable', 'read'], ['renamed' => 'right-label']);

        $container->set($function, static fn(int $replacement): array => ['service', $replacement]);
        $report[] = $container->call($function, ['replacement' => 42]);
        $report[] = $container->call(strtoupper($function));
        $container->set($function, 'non-callable-value');
        $report[] = failure(static fn(): mixed => $container->call($function));
        $container->set($function, $function);
        $report[] = $container->call($function);

        expect(array_slice($report, 0, 6))->toBe(['default', 'explicit', 'default', 'explicit', 'default', 'explicit'])
            ->and(array_slice($report, 6, 3))->toBe([['first', 9], ['first', 9], ['first', 9]])
            ->and($report[9])->toBe(['left', 'first', 'left-label', []])
            ->and($report[10])->toBe(['right', 'first', 'right-label', []])
            ->and($report[11])->toBe(['service', 42])
            ->and($report[12])->toBe('default')
            ->and($report[13][0])->toBe(InvalidCallableException::class)
            ->and($report[13][1])->toContain('is not invokable')
            ->and($report[14])->toBe('default');
        $reports[] = $report;
    }
    expect($reports[0])->toBe($reports[1]);
});

test('reused and fresh containers preserve declaration and rebound scopes for contextual types', function (): void {
    $fixture = new ContainerBuilder()->addServices([
        TypeAncestor::class => new TypeAncestor(),
        ReboundScope::class => new ReboundScope(),
    ]);
    $reused = $fixture->build();
    $owner = new TypeChild();
    $rebound = TypeScope::callback()->bindTo(null, ReboundScope::class);
    if ($rebound === null) {
        throw new \LogicException('The contextual fixture must be rebound.');
    }
    $calls = [[$owner, 'contextual'], $owner->contextual(...), TypeScope::callback(), TypeChild::callback()];

    foreach ([new TypeScope(), new TypeChild()] as $current) {
        $fixture->addService(TypeScope::class, $current);
        $reused->set(TypeScope::class, $current);
        foreach ($calls as $callable) {
            $expected = [$current::class, TypeAncestor::class];
            $error = failure(static fn(): mixed => $reused->call($callable, ['value' => new TypeAncestor()]));
            $freshError = failure(static fn(): mixed => $fixture->build()->call($callable, ['value' => new TypeAncestor()]));

            expect($reused->call($callable))->toBe($expected)
                ->and($fixture->build()->call($callable))->toBe($expected)
                ->and($error[0])->toBe(ResolutionException::class)
                ->and($freshError)->toBe($error);
        }

        expect($reused->call($rebound))->toBe([ReboundScope::class, TypeAncestor::class])
            ->and($fixture->build()->call($rebound))->toBe([ReboundScope::class, TypeAncestor::class]);
    }
});

test('warm and fresh invocation preserve failure ownership native omission and recovery', function (): void {
    [$reused] = auditContainer();
    $original = new \DomainException('Target body failed.');
    $target = static function (Dependency $dependency) use ($original): never {
        throw $original;
    };

    for ($round = 0; $round < 2; ++$round) {
        [$fresh] = auditContainer();
        $reports = [];
        foreach ([$reused, $fresh] as $container) {
            $report = [];
            foreach ([$target, new PreparedCallable($target)] as $callable) {
                try {
                    $container->call($callable, [new Dependency('explicit')]);
                    \PHPUnit\Framework\Assert::fail('The target body must throw.');
                } catch (\DomainException $exception) {
                    expect($exception)->toBe($original);
                }
            }
            $invalidValue = failure(static fn(): mixed => $container->call([MethodOwner::class, 'staticRead'], ['number' => 'wrong']));
            expect($invalidValue[0])->toBe(ResolutionException::class);
            $report[] = $invalidValue;
            $report[] = $container->call([MethodOwner::class, 'staticRead'], ['number' => 17]);
            $byReference = failure(static fn(): mixed => $container->call('sort', ['array' => [2, 1]]));
            expect($byReference[0])->toBe(ResolutionException::class);
            $report[] = $byReference;
            $nativeOmission = failure(static fn(): mixed => $container->call('array_keys', ['array' => ['a' => 1], 'strict' => true]));
            expect($nativeOmission[0])->toBe(\ArgumentCountError::class);
            $report[] = $nativeOmission;
            $report[] = $container->call('array_keys', ['array' => ['a' => 1, 'nil' => null]]);
            $report[] = $container->call('php_sapi_name', ['unused' => 'ignored']);

            expect($report[1])->toBe(['first', 17])
                ->and($report[4])->toBe(['a', 'nil'])
                ->and($report[5])->toBe(PHP_SAPI);
            $reports[] = $report;
        }
        expect($reports[0])->toBe($reports[1]);
    }
});

test('callable metadata does not retain supplied owners closures or prepared adapters', function (bool $withRule): void {
    [$container, $probe] = auditContainer($withRule);
    $owner = new MethodOwner('owner');
    $magic = new MagicOwner('magic');
    $capture = new \stdClass();
    $closure = static fn(Dependency $dependency): array => [$dependency->value, $capture];
    $bound = $magic->privateCallback();
    $prepared = new PreparedCallable($closure);
    $references = array_map(WeakReference::create(...), [$owner, $magic, $capture, $closure, $bound, $prepared]);

    expect($container->call([$owner, 'read']))->toBe(['owner', 'first', 'native', []]);
    $first = $probe->calls['read'][0];
    expect($container->call([$owner, 'read']))->toBe(['owner', 'first', 'native', []]);
    $second = $probe->calls['read'][0];
    expect($first)->toBeGreaterThan(0)
        ->and($second > $first)->toBe($withRule)
        ->and($container->call($owner))->toBe(['owner', 'first', 'invoke', []])
        ->and($container->call($closure))->toBe(['first', $capture])
        ->and($container->call([$closure, '__invoke']))->toBe(['first', $capture])
        ->and($container->call($bound))->toBe(['magic', 'first'])
        ->and($container->call($prepared, [new Dependency('prepared')]))->toBe(['prepared', $capture]);
    unset($owner, $magic, $capture, $closure, $bound, $prepared);
    gc_collect_cycles();

    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }
})->with(['memoized method plan' => false, 'method plan with a composition rule' => true]);

test('repeated named reflectors reuse stable targets while caller-owned targets remain collectable', function (bool $uncached): void {
    [, , $runtime] = auditContainer($uncached);
    $resolver = $runtime->parameters;
    $references = [];
    for ($iteration = 0; $iteration < 40; ++$iteration) {
        $parameter = new ReflectionFunction(__NAMESPACE__ . '\\customValue')->getParameters()[0];
        $target = new ParameterTarget($parameter);
        $references[] = WeakReference::create($target);
        expect($resolver->resolveTargets([$target]))->toBe(['default']);
    }
    unset($parameter, $target);
    gc_collect_cycles();
    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }

    $known = $resolver->target(new ReflectionFunction(__NAMESPACE__ . '\\customValue')->getParameters()[0]);
    $reflectors = [];
    for ($iteration = 0; $iteration < 40; ++$iteration) {
        $parameter = new ReflectionFunction(__NAMESPACE__ . '\\customValue')->getParameters()[0];
        $reflectors[] = WeakReference::create($parameter);
        expect($resolver->target($parameter))->toBe($known)
            ->and($resolver->resolve([$parameter]))->toBe(['default']);
    }
    unset($parameter);
    gc_collect_cycles();
    foreach ($reflectors as $reference) {
        expect($reference->get())->toBeNull();
    }
})->with(['cached' => false, 'fresh' => true]);
