<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\Transform;

use Attribute;
use Closure;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;
use WeakReference;

#[Attribute(Attribute::TARGET_ALL)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function builder(bool $uncached = false): ContainerBuilder
{
    $builder = new ContainerBuilder(new Config(['label' => '  configured  '], new Environment([])));
    $builder->addService(CasterProviderInterface::class, new TestCasterProvider());
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

final class State
{
    /** @var list<mixed> */
    public static array $inputs = [];
    public static ?Throwable $error = null;
    /** @var (Closure():string)|null */
    public static ?Closure $reentry = null;
    /** @var (Closure():void)|null */
    public static ?Closure $reveal = null;
    /** @var list<WeakReference<object>> */
    public static array $references = [];
}

final class Source
{
    public int $visits = 0;
}

final readonly class Dependency
{
    public function __construct(public string $label = 'dependency') {}
}

interface MissingDependency {}

function identity(mixed $input): mixed
{
    State::$inputs[] = $input;
    return $input;
}

function trimmed(string $input): string
{
    State::$inputs[] = $input;
    return trim($input);
}

function padded(string $input): string
{
    State::$inputs[] = $input;
    return '  ' . $input . '  ';
}

function bracketed(string $input): string
{
    State::$inputs[] = $input;
    return '[' . $input . ']';
}

function length(string $input): int
{
    State::$inputs[] = $input;
    return strlen($input);
}

function stringified(int $input): string
{
    State::$inputs[] = $input;
    return (string) $input;
}

function noArguments(): string
{
    State::$inputs[] = func_num_args();
    return 'zero';
}

function withoutReturn(mixed $input): void
{
    State::$inputs[] = $input;
}

/**
 * @param array<int|string,mixed> $input
 * @return array<int|string,mixed>
 */
function reversed(array $input): array
{
    State::$inputs[] = $input;
    return array_reverse($input, true);
}

function visited(Source $input): Source
{
    ++$input->visits;
    return $input;
}

function withSuffix(
    string $input,
    Dependency $dependency,
    #[EntryId('transform.marker')] string $marker,
    string $fallback = 'default',
    ?MissingDependency $nullable = null,
    string ...$items,
): string {
    State::$inputs[] = [$input, $dependency->label, $marker, $fallback, $nullable, $items];
    return $input . ':' . $dependency->label . ':' . $marker;
}

function fails(mixed $input): never
{
    State::$inputs[] = $input;
    throw State::$error ?? new RuntimeException('transform failed');
}

function reenters(string $input): string
{
    State::$inputs[] = $input;
    if ($input === 'outer' && State::$reentry !== null) {
        return $input . ':' . (State::$reentry)();
    }
    return $input;
}

function withRequest(string $input, #[CurrentRequest] ServerRequestInterface $request): string
{
    State::$inputs[] = $input;
    return $input . ':' . $request->getMethod();
}

function reveals(string $input): string
{
    State::$inputs[] = $input;
    State::$reveal?->__invoke();
    return $input;
}

// Runtime closure constants keep these public contracts executable on PHP 8.4.
define(__NAMESPACE__ . '\\IDENTITY', identity(...));
define(__NAMESPACE__ . '\\TRIMMED', trimmed(...));
define(__NAMESPACE__ . '\\PADDED', padded(...));
define(__NAMESPACE__ . '\\BRACKETED', bracketed(...));
define(__NAMESPACE__ . '\\LENGTH', length(...));
define(__NAMESPACE__ . '\\STRINGIFIED', stringified(...));
define(__NAMESPACE__ . '\\ZERO', noArguments(...));
define(__NAMESPACE__ . '\\VOID_RESULT', withoutReturn(...));
define(__NAMESPACE__ . '\\REVERSED', reversed(...));
define(__NAMESPACE__ . '\\VISITED', visited(...));
define(__NAMESPACE__ . '\\WITH_SUFFIX', withSuffix(...));
define(__NAMESPACE__ . '\\FAILS', fails(...));
define(__NAMESPACE__ . '\\REENTERS', reenters(...));
define(__NAMESPACE__ . '\\WITH_REQUEST', withRequest(...));
define(__NAMESPACE__ . '\\REVEALS', reveals(...));

beforeEach(function (): void {
    State::$inputs = [];
    State::$error = null;
    State::$reentry = null;
    State::$reveal = null;
    State::$references = [];
});

afterEach(function (): void {
    State::$inputs = [];
    State::$error = null;
    State::$reentry = null;
    State::$reveal = null;
    State::$references = [];
});

test('transform uses exact explicit named positional and null inputs before defaults', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $callback = static fn(#[Transform(IDENTITY)] mixed $value = 'fallback'): mixed => $value;

    expect($container->call($callback, ['value' => 'named']))->toBe('named')
        ->and($container->call($callback, [0 => 'position']))->toBe('position')
        ->and($container->call($callback, ['value' => null]))->toBeNull()
        ->and(State::$inputs)->toBe(['named', 'position', null]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform uses fresh native defaults nullable fallback and one empty variadic collection', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $defaults = static fn(#[Transform(VISITED)] Source $value = new Source()): Source => $value;
    $first = $container->call($defaults);
    $second = $container->call($defaults);
    if (!$first instanceof Source || !$second instanceof Source) {
        throw new LogicException('Expected transformed default objects.');
    }
    expect($first)->not->toBe($second)
        ->and($first->visits)->toBe(1)
        ->and($second->visits)->toBe(1)
        ->and($container->call(static fn(#[Transform(TRIMMED)] string $value = '  default  '): string => $value))->toBe('default')
        ->and($container->call(static fn(#[Transform(IDENTITY)] ?string $value): ?string => $value))->toBeNull()
        ->and($container->call(static fn(#[Transform(REVERSED)] string ...$values): array => $values))->toBe([])
        ->and(State::$inputs)->toBe(['  default  ', null, []]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('zero parameter transforms require a source and receive zero native arguments', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $required = static fn(#[Transform(ZERO)] string $value): string => $value;
    expect(fn() => $container->call($required))->toThrow(ResolutionException::class, 'missing required value')
        ->and(State::$inputs)->toBe([])
        ->and($container->call($required, ['value' => 'source']))->toBe('zero')
        ->and(State::$inputs)->toBe([0]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform runs after configured sources and can consume shared and fresh objects', function (bool $uncached): void {
    $shared = new Source();
    $container = builder($uncached)->addService('transform.source', $shared)->build();
    $config = static fn(#[Transform(TRIMMED), ConfigAttribute('label')] string $value): string => $value;
    $entry = static fn(#[Transform(VISITED), EntryId('transform.source')] Source $value): Source => $value;
    $fresh = static fn(#[Transform(VISITED), Make(Source::class)] Source $value): Source => $value;
    $created = $container->call($fresh);
    if (!$created instanceof Source) {
        throw new LogicException('Expected a transformed fresh object.');
    }

    expect($container->call($config))->toBe('configured')
        ->and($container->call($entry))->toBe($shared)
        ->and($shared->visits)->toBe(1)
        ->and($created)->not->toBe($shared)
        ->and($created->visits)->toBe(1);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform does not implicitly autowire its source parameter', function (bool $uncached): void {
    $created = 0;
    $container = builder($uncached)->addFactory(Source::class, static function () use (&$created): Source {
        ++$created;
        return new Source();
    })->build();

    expect(fn() => $container->call(static fn(#[Transform(VISITED)] Source $value): Source => $value))
        ->toThrow(ResolutionException::class, 'missing required value')
        ->and($created)->toBe(0);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('invalid supplied transform input does not fall back to a native default', function (bool $uncached): void {
    $container = builder($uncached)->build();
    expect(fn() => $container->call(static fn(#[Transform(TRIMMED)] string $value = 'fallback'): string => $value, ['value' => null]))
        ->toThrow(ResolutionException::class)
        ->and(State::$inputs)->toBe([]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('repeatable transform and cast preserve mutual declaration order', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $first = static fn(#[Transform(PADDED), Cast('trim'), Transform(BRACKETED)] string $value): string => $value;
    $second = static fn(#[Cast('trim'), Transform(PADDED), Transform(BRACKETED)] string $value): string => $value;

    expect($container->call($first, ['value' => 'a']))->toBe('[a]')
        ->and($container->call($second, ['value' => '  b  ']))->toBe('[  b  ]')
        ->and(State::$inputs)->toBe(['a', 'a', 'b', '  b  ']);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform validates only the final parameter value and preserves void as null', function (bool $uncached): void {
    $container = builder($uncached)->build();
    expect($container->call(static fn(#[Transform(LENGTH), Transform(STRINGIFIED)] string $value): string => $value, ['value' => 'four']))->toBe('4')
        ->and($container->call(static fn(#[Transform(VOID_RESULT)] ?string $value): ?string => $value, ['value' => 'source']))->toBeNull()
        ->and(State::$inputs)->toBe(['four', 4, 'source']);

    expect(fn() => $container->call(static fn(#[Transform(LENGTH)] string $value): string => $value, ['value' => 'wrong']))
        ->toThrow(ResolutionException::class);
    expect(State::$inputs)->toBe(['four', 4, 'source', 'wrong']);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform receives the entire variadic collection once and validates resulting element types', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $callback = static fn(#[Transform(REVERSED)] string ...$values): array => $values;
    expect($container->call($callback, ['values' => ['left' => 'a', 'right' => 'b']]))
        ->toBe(['right' => 'b', 'left' => 'a'])
        ->and(State::$inputs)->toBe([['left' => 'a', 'right' => 'b']]);
    expect(fn() => $container->call($callback, ['values' => ['bad' => new Source()]]))
        ->toThrow(ResolutionException::class);
    // Reversing this valid collection would put a named argument before a positional one.
    expect(fn() => $container->call($callback, ['values' => [0 => 'positional', 'named' => 'named']]))
        ->toThrow(ResolutionException::class);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform suffix dependencies never inherit the outer parameter bag', function (bool $uncached): void {
    $container = builder($uncached)
        ->addService(Dependency::class, new Dependency('trusted'))
        ->addService('transform.marker', 'trusted-marker')
        ->build();
    $callback = static fn(#[Transform(WITH_SUFFIX)] string $value): string => $value;
    $untrusted = new Dependency('untrusted');
    expect($container->call($callback, [
        'value' => 'source',
        1 => $untrusted,
        'input' => 'replacement',
        'dependency' => $untrusted,
        Dependency::class => $untrusted,
        'marker' => 'replacement',
        'fallback' => 'replacement',
        'nullable' => 'replacement',
        'items' => ['replacement'],
    ]))->toBe('source:trusted:trusted-marker')
        ->and(State::$inputs)->toBe([['source', 'trusted', 'trusted-marker', 'default', null, []]]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform suffix does not inherit the ambient HTTP invocation request', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $request = new ServerRequest('POST', '/transform');
    expect(fn() => $container->call(
        static fn(#[Transform(WITH_REQUEST)] string $value): string => $value,
        ['value' => 'input', ServerRequestInterface::class => $request],
    ))->toThrow(ResolutionException::class)
        ->and(State::$inputs)->toBe([]);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('warmed transform suffix plans observe dependency and provider replacement', function (bool $uncached): void {
    $container = builder($uncached)
        ->addService(Dependency::class, new Dependency('first'))
        ->addService('transform.marker', 'one')
        ->build();
    $callback = static fn(#[Transform(WITH_SUFFIX)] string $value): string => $value;
    expect($container->call($callback, ['value' => 'source']))->toBe('source:first:one');
    $container->set(Dependency::class, new Dependency('second'));
    $container->set('transform.marker', 'two');
    expect($container->call($callback, ['value' => 'source']))->toBe('source:second:two')
        ->and(State::$inputs)->toHaveCount(2);
})->with(['cached plans' => false, 'uncached plans' => true]);

test('transform exceptions retain the owning parameter and original cause', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $cause = new RuntimeException('original transform failure');
    State::$error = $cause;
    try {
        $container->call(static fn(#[Transform(FAILS)] string $value): string => $value, ['value' => 'input']);
        Assert::fail('Expected the transformer to fail.');
    } catch (ResolutionException $error) {
        expect($error->parameterName)->toBe('value')
            ->and($error->getPrevious())->toBe($cause)
            ->and($error->providedParameterTypes)->toBe(['value' => 'string']);
    }
    $identity = new ResolutionException('existing container failure');
    State::$error = $identity;
    try {
        $container->call(static fn(#[Transform(FAILS)] string $value): string => $value, ['value' => 'input']);
        Assert::fail('Expected the transformer to fail.');
    } catch (ResolutionException $error) {
        expect($error)->toBe($identity);
    }
})->with(['cached plans' => false, 'uncached plans' => true]);

test('reentrant transformation retains the independent outer value and invokes each usage once', function (bool $uncached): void {
    $container = builder($uncached)->build();
    $callback = static fn(#[Transform(REENTERS), Transform(BRACKETED)] string $value): string => $value;
    State::$reentry = static function () use ($container, $callback): string {
        $value = $container->call($callback, ['value' => 'inner']);
        if (!is_string($value)) {
            throw new LogicException('Expected the nested transformed string.');
        }
        return $value;
    };

    expect($container->call($callback, ['value' => 'outer']))->toBe('[outer:[inner]]')
        ->and(State::$inputs)->toBe(['outer', 'inner', 'inner', 'outer:[inner]']);
})->with(['cached plans' => false, 'uncached plans' => true]);

#[Attribute(Attribute::TARGET_PARAMETER)]
final class PendingTransformer
{
    public static ?string $reveal = null;
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
        if (self::$reveal !== null && !class_exists(self::$reveal, false)) {
            class_alias(Transform::class, self::$reveal);
        }
    }
}

final class PendingHandler implements ParameterAttributeHandlerInterface
{
    public int $calls = 0;

    public function resolveParameter(object $attribute, ParameterTarget $target, ParameterResolutionContext $context, AttributePlan $plan, ParameterAttributeValue $value): ParameterAttributeValue
    {
        ++$this->calls;
        return $value;
    }
}

test('late transform discovery does not replay completed callbacks or reconstruct a pending attribute', function (bool $uncached): void {
    $alias = 'LateTransform_' . bin2hex(random_bytes(5));
    PendingTransformer::$reveal = __NAMESPACE__ . '\\' . $alias;
    PendingTransformer::$constructions = 0;
    $callback = eval(sprintf(
        'namespace %s; return static fn(#[\\Componenta\\DI\\Attribute\\Transform(TRIMMED), PendingTransformer, %s(BRACKETED)] string $value): string => $value;',
        __NAMESPACE__,
        $alias,
    ));
    if (!$callback instanceof Closure) {
        throw new LogicException('Expected the late-transform callback.');
    }
    $handler = new PendingHandler();
    $container = builder($uncached)->addAttributeDefinition(new AttributeDefinition(
        PendingTransformer::class,
        $handler,
        [ValueTransformer::class],
    ))->build();

    try {
        expect($container->call($callback, ['value' => '  late  ']))->toBe('[late]')
            ->and(State::$inputs)->toBe(['  late  ', 'late'])
            ->and(PendingTransformer::$constructions)->toBe(1)
            ->and($handler->calls)->toBe(1);
    } finally {
        PendingTransformer::$reveal = null;
    }
})->with(['cached plans' => false, 'uncached plans' => true]);

test('a transformer cannot reveal an earlier provider and replay a completed callback', function (bool $uncached): void {
    $alias = 'LateProvider_' . bin2hex(random_bytes(5));
    $callback = eval(sprintf(
        'namespace %s; return static fn(#[\\Componenta\\DI\\Attribute\\Transform(REVEALS), %s("label")] string $value): string => $value;',
        __NAMESPACE__,
        $alias,
    ));
    if (!$callback instanceof Closure) {
        throw new LogicException('Expected the late-provider callback.');
    }
    State::$reveal = static function () use ($alias): void {
        class_alias(ConfigAttribute::class, __NAMESPACE__ . '\\' . $alias);
    };
    $container = builder($uncached)->build();
    expect(fn() => $container->call($callback, ['value' => 'input']))
        ->toThrow(AttributeCompositionException::class)
        ->and(State::$inputs)->toBe(['input']);
})->with(['cached plans' => false, 'uncached plans' => true]);

final class TransientValue
{
    public function __construct(public int $number) {}
}

test('native inline transform closures keep fresh state without retaining values dependencies or local objects', function (bool $uncached): void {
    $callback = eval(<<<'PHP'
    namespace Componenta\DI\Tests\Contract\Transform;
    return static fn(
        #[\Componenta\DI\Attribute\Transform(static function (
            TransientValue $input,
            #[\Componenta\DI\Attribute\Make(Dependency::class)] Dependency $dependency,
        ): int {
            static $count = 0;
            static $local = new \stdClass();
            State::$references[] = \WeakReference::create($input);
            State::$references[] = \WeakReference::create($dependency);
            State::$references[] = \WeakReference::create($local);
            return $input->number + ++$count;
        })]
        int $value,
    ): int => $value;
    PHP);
    if (!$callback instanceof Closure) {
        throw new LogicException('Expected the native transform callback.');
    }
    $container = builder($uncached)->build();
    foreach ([10, 20, 30] as $number) {
        $value = new TransientValue($number);
        expect($container->call($callback, ['value' => $value]))->toBe($number + 1);
        unset($value);
        gc_collect_cycles();
        foreach (State::$references as $reference) {
            expect($reference->get())->toBeNull();
        }
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->skip(PHP_VERSION_ID < 80500, 'Native closure attribute arguments require PHP 8.5.');

test('native transform first-class references preserve private static scope and current alias dependencies', function (bool $uncached): void {
    $name = 'NativeReferences_' . bin2hex(random_bytes(5));
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s {
            private const string PREFIX = 'private';
            private static function format(string $input, Dependency $dependency): string {
                static $calls = 0;
                return self::PREFIX . ':' . $input . ':' . $dependency->label . ':' . ++$calls;
            }
            public static function consume(
                #[\Componenta\DI\Attribute\Transform(self::format(...))] string $value,
            ): string { return $value; }
            public static function named(
                #[\Componenta\DI\Attribute\Transform(withSuffix(...))] string $value,
            ): string { return $value; }
        }
        PHP,
        __NAMESPACE__,
        $name,
    ));
    $class = __NAMESPACE__ . '\\' . $name;
    if (!class_exists($class, false)) {
        throw new LogicException('Expected the first-class-reference fixture.');
    }
    $container = builder($uncached)
        ->addService('native.first', new Dependency('first'))
        ->addService('native.second', new Dependency('second'))
        ->addAlias(Dependency::class, 'native.first')
        ->addService('transform.marker', 'marker')
        ->build();
    expect($container->call([$class, 'consume'], ['value' => 'input']))->toBe('private:input:first:1')
        ->and($container->call([$class, 'named'], ['value' => 'input']))->toBe('input:first:marker');
    $container->alias(Dependency::class, 'native.second');
    expect($container->call([$class, 'consume'], ['value' => 'input']))->toBe('private:input:second:2')
        ->and($container->call([$class, 'named'], ['value' => 'input']))->toBe('input:second:marker');
})->with(['cached plans' => false, 'uncached plans' => true])
    ->skip(PHP_VERSION_ID < 80500, 'Native first-class attribute arguments require PHP 8.5.');
