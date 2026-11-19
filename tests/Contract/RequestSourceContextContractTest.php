<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestSourceContext;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Metadata {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Observation
{
    public static bool $armed = false;
    public static string $field = '';
    public static string $source = '';
    public static bool $sourceIsBinding = true;
    public static int $dependencies = 0;
    /** @var list<mixed> */
    public static array $values = [];
    /** @var list<int> */
    public static array $handlerArguments = [];
    /** @var list<mixed> */
    public static array $suffixValues = [];
    public static int $constructed = 0;
    public static int $invoked = 0;

    public static function reset(): void
    {
        self::$values = [];
        self::$handlerArguments = [];
        self::$suffixValues = [];
        self::$dependencies = 0;
        self::$constructed = self::$invoked = 0;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Reveal
{
    public function __construct(string $class, bool $source)
    {
        if (Observation::$armed && !class_exists($class, false)) {
            class_alias($source ? EntryId::class : Metadata::class, $class);
        }
    }
}

final class ContextObserver implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        Observation::$values[] = $context->provided[Observation::$field] ?? null;
        Observation::$handlerArguments[] = func_num_args();
        return $value;
    }
}

function target(string $parameters): Closure
{
    $dto = 'Dto_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; final class %s { public function __construct(%s) { ++Observation::$constructed; } }',
        __NAMESPACE__,
        $dto,
        $parameters,
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[\\Componenta\\DI\\Attribute\\MapRequest(sources: [\\Componenta\\DI\\Attribute\\RequestDataSource::Query])] %s $dto): string { ++Observation::$invoked; return $dto->{Observation::$field}; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new \LogicException('Expected a public request-mapping callback.');
    }
    return $target;
}

test('late sources guard every mapped field exposed to a newly dispatched parameter handler', function (
    bool $sibling,
    bool $mappedFirst,
    bool $warm,
    bool $uncached,
    bool $conflict,
): void {
    Observation::$armed = false;
    Observation::$field = $sibling ? 'role' : 'first';
    Observation::reset();
    $source = 'Source_' . bin2hex(random_bytes(5));
    $target = target(sprintf(
        '#[%sReveal(%s::class, %s)] public string $first%s, %spublic string $role%s',
        $sibling ? '' : $source . '("trusted"), ',
        $source,
        $conflict ? 'true' : 'false',
        $mappedFirst ? '' : ' = "ordinary value"',
        $sibling ? '#[' . $source . '("trusted")] ' : '',
        $mappedFirst ? '' : ' = "fallback"',
    ));
    $builder = (new ContainerBuilder())
        ->addService('trusted', 'trusted value')
        ->addAttributeDefinition(new AttributeDefinition(Reveal::class, new ContextObserver(), [ValueTransformer::class]));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $query = [
        'first' => $sibling ? 'ordinary value' : 'untrusted',
        'role' => $sibling ? 'untrusted' : 'ordinary value',
    ];
    if (!$mappedFirst) {
        unset($query['first']);
    }
    $request = (new ServerRequest('GET', '/'))->withQueryParams($query);
    if ($warm) {
        $container->call($target, [ServerRequestInterface::class => $request]);
        expect(Observation::$values)->toBe(['untrusted'])
            ->and(Observation::$constructed)->toBe(1)
            ->and(Observation::$invoked)->toBe(1)
            ->and(class_exists(__NAMESPACE__ . '\\' . $source, false))->toBeFalse();
        Observation::reset();
    }
    Observation::$armed = true;

    if ($conflict) {
        expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
            ->toThrow(RequestParameterSourceConflictException::class, Observation::$field)
            ->and(Observation::$values)->toBe([])
            ->and(Observation::$handlerArguments)->toBe([])
            ->and(Observation::$constructed)->toBe(0)
            ->and(Observation::$invoked)->toBe(0);

        $provided = $request->getQueryParams();
        unset($provided[Observation::$field]);
        $result = $container->call($target, [ServerRequestInterface::class => $request->withQueryParams($provided)]);
        expect($result)->toBe('trusted value')
            ->and(Observation::$values)->toBe([null])
            ->and(Observation::$handlerArguments)->toBe([5])
            ->and(Observation::$constructed)->toBe(1)
            ->and(Observation::$invoked)->toBe(1);
    } else {
        $result = $container->call($target, [ServerRequestInterface::class => $request]);
        expect($result)->toBe('untrusted')
            ->and(Observation::$values)->toBe(['untrusted'])
            ->and(Observation::$handlerArguments)->toBe([5])
            ->and(Observation::$constructed)->toBe(1)
            ->and(Observation::$invoked)->toBe(1);
    }
    expect(class_exists(__NAMESPACE__ . '\\' . $source, false))->toBeTrue();
})->with([
    'same parameter' => [false, true],
    'sibling parameter' => [true, true],
    'sibling after an unmapped parameter' => [true, false],
])
    ->with(['cold plan' => false, 'warm plan' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['source' => true, 'unrelated metadata' => false]);

final class RevealingDependency
{
    public function __construct()
    {
        ++Observation::$dependencies;
        class_alias(Observation::$sourceIsBinding ? EntryId::class : Metadata::class, Observation::$source);
    }
}

final class SuffixObserver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'suffixProbe';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        Observation::$suffixValues[] = $context->resolved[0];
        return [$target->position, 'observed'];
    }
}

function observe(string $semantic, RevealingDependency $dependency, string $suffixProbe): string
{
    Observation::$values[] = $semantic;
    return $semantic;
}

define(__NAMESPACE__ . '\\OBSERVER', observe(...));

test('a sibling source revealed by a transformer dependency guards its remaining suffix and callback', function (
    bool $uncached,
    bool $conflict,
): void {
    Observation::reset();
    Observation::$field = 'role';
    Observation::$sourceIsBinding = $conflict;
    $source = 'SuffixSource_' . bin2hex(random_bytes(5));
    Observation::$source = __NAMESPACE__ . '\\' . $source;
    $target = target(sprintf(
        '#[\\Componenta\\DI\\Attribute\\Transform(OBSERVER)] public string $first, #[%s("trusted")] public string $role',
        $source,
    ));
    $builder = (new ContainerBuilder())
        ->addService('trusted', 'trusted value')
        ->addParameterResolver(new SuffixObserver(), 2000);
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['first' => 'ordinary value', 'role' => 'untrusted']);

    if ($conflict) {
        expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
            ->toThrow(RequestParameterSourceConflictException::class, 'role')
            ->and(Observation::$values)->toBe([])
            ->and(Observation::$suffixValues)->toBe([])
            ->and(Observation::$constructed)->toBe(0)
            ->and(Observation::$invoked)->toBe(0);
        $request = $request->withQueryParams(['first' => 'ordinary value']);
    }

    expect($container->call($target, [ServerRequestInterface::class => $request]))
        ->toBe($conflict ? 'trusted value' : 'untrusted')
        ->and(Observation::$dependencies)->toBe(1)
        ->and(Observation::$values)->toBe(['ordinary value'])
        ->and(Observation::$suffixValues)->toBe(['ordinary value'])
        ->and(Observation::$constructed)->toBe(1)
        ->and(Observation::$invoked)->toBe(1);
})->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['source' => true, 'unrelated metadata' => false]);

#[Attribute(Attribute::TARGET_PARAMETER)]
final class SourceMarker implements ParameterSourceAttributeInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class RevealSourceMarker
{
    public function __construct(string $source)
    {
        class_alias(SourceMarker::class, $source);
    }
}

test('unavailable metadata before a late source cannot hide mapped conflicts from context consumers', function (bool $uncached): void {
    Observation::reset();
    Observation::$field = 'role';
    $suffix = bin2hex(random_bytes(5));
    $unknown = 'Unavailable_' . $suffix;
    $source = 'LateSource_' . $suffix;
    $target = target(sprintf(
        '#[%s] public string $first, #[%s, RevealSourceMarker(%s::class)] public string $role',
        $unknown,
        $source,
        $source,
    ));
    $builder = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        RevealSourceMarker::class,
        new ContextObserver(),
        [ValueTransformer::class],
    ));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['first' => 'ordinary', 'role' => 'untrusted']);

    expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(Observation::$values)->toBe([])
        ->and(Observation::$handlerArguments)->toBe([])
        ->and(Observation::$constructed)->toBe(0)
        ->and(Observation::$invoked)->toBe(0)
        ->and(class_exists(__NAMESPACE__ . '\\' . $source, false))->toBeTrue()
        ->and(class_exists(__NAMESPACE__ . '\\' . $unknown, false))->toBeFalse();
})->with(['cached plans' => false, 'non-memoized plans' => true]);

final readonly class TypedValue
{
    public function __construct(public string $label = 'ordinary default') {}
}

test('a late source guards mapped type keys before a custom handler sees the context', function (
    bool $uncached,
    bool $conflict,
): void {
    Observation::reset();
    Observation::$armed = true;
    Observation::$field = TypedValue::class;
    $source = 'TypeSource_' . bin2hex(random_bytes(5));
    $dto = 'TypedDto_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; final class %s { public function __construct(#[%s("typed.trusted"), Reveal(%s::class, %s)] public TypedValue $value) { ++Observation::$constructed; } }',
        __NAMESPACE__,
        $dto,
        $source,
        $source,
        $conflict ? 'true' : 'false',
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[\\Componenta\\DI\\Attribute\\MapRequest(sources: [\\Componenta\\DI\\Attribute\\RequestDataSource::Query])] %s $dto): string { ++Observation::$invoked; return $dto->value->label; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new \LogicException('Expected the typed public request-mapping target.');
    }
    $builder = (new ContainerBuilder())
        ->addService('typed.trusted', new TypedValue('trusted value'))
        ->addAttributeDefinition(new AttributeDefinition(Reveal::class, new ContextObserver(), [ValueTransformer::class]));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams([TypedValue::class => 'untrusted']);

    if ($conflict) {
        expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
            ->toThrow(RequestParameterSourceConflictException::class, TypedValue::class)
            ->and(Observation::$values)->toBe([])
            ->and(Observation::$constructed)->toBe(0)
            ->and(Observation::$invoked)->toBe(0);
        $request = $request->withQueryParams([]);
    }
    expect($container->call($target, [ServerRequestInterface::class => $request]))
        ->toBe($conflict ? 'trusted value' : 'ordinary default')
        ->and(Observation::$values)->toBe([$conflict ? null : 'untrusted'])
        ->and(Observation::$handlerArguments)->toBe([5])
        ->and(Observation::$constructed)->toBe(1)
        ->and(Observation::$invoked)->toBe(1);
})->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['source' => true, 'unrelated metadata' => false]);
