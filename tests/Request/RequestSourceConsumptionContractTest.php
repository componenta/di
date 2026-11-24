<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestSourceConsumptionContract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
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
    /** @var list<string> */
    public static array $values = [];
    public static int $constructed = 0;
    public static int $invoked = 0;
    public static string $source = '';
    public static int $dependencies = 0;
    /** @var list<string> */
    public static array $suffixValues = [];
    /** @var list<int> */
    public static array $handlerArguments = [];
}

function observe(string $value): string
{
    Observation::$values[] = $value;
    return $value;
}

define(__NAMESPACE__ . '\\OBSERVER', observe(...));

function builder(bool $uncached): ContainerBuilder
{
    $builder = (new ContainerBuilder())->addService('trusted', 'trusted value');
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
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
        'namespace %s; return static function (#[\\Componenta\\DI\\Attribute\\MapRequest(sources: [\\Componenta\\DI\\Attribute\\RequestDataSource::Query])] %s $dto): void { ++Observation::$invoked; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new \LogicException('Expected the public request mapping target.');
    }
    return $target;
}

function resetObservation(): void
{
    Observation::$values = [];
    Observation::$constructed = 0;
    Observation::$invoked = 0;
    Observation::$dependencies = 0;
    Observation::$suffixValues = [];
    Observation::$handlerArguments = [];
}

beforeEach(function (): void {
    resetObservation();
});

test('late source discovery rejects mapped data before its parameter transformer consumes it', function (
    bool $laterParameter,
    bool $uncached,
): void {
    $blocked = 0;
    $unavailable = 0;
    // This bounded sweep spans the cold cached/non-memoized preparation and
    // execution paths; 320 is test input, not a production retry or poll count.
    foreach ([...range(1, 320), PHP_INT_MAX] as $availableOnAttempt) {
        $suffix = bin2hex(random_bytes(5));
        $source = 'Source_' . $suffix;
        $metadata = 'Metadata_' . $suffix;
        $parameters = $laterParameter
            ? sprintf('#[%s("trusted"), \\Componenta\\DI\\Attribute\\Transform(OBSERVER)] string $role, #[%s] string $other = "default"', $source, $metadata)
            : sprintf('#[%s("trusted"), %s, \\Componenta\\DI\\Attribute\\Transform(OBSERVER)] string $role', $source, $metadata);
        $target = target($parameters);

        $attempts = 0;
        $loadedBeforeConsumption = false;
        $loader = static function (string $class) use ($source, $metadata, $availableOnAttempt, &$attempts, &$loadedBeforeConsumption): void {
            if ($class !== __NAMESPACE__ . '\\' . $metadata || ++$attempts !== $availableOnAttempt) {
                return;
            }
            $loadedBeforeConsumption = Observation::$values === [];
            class_alias(EntryId::class, __NAMESPACE__ . '\\' . $source);
            class_alias(Metadata::class, __NAMESPACE__ . '\\' . $metadata);
        };
        spl_autoload_register($loader);

        try {
            resetObservation();
            $container = builder($uncached)->build();
            $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);
            $failure = null;
            try {
                $container->call($target, [ServerRequestInterface::class => $request]);
            } catch (RequestParameterSourceConflictException|AttributeCompositionException $error) {
                $failure = $error;
            }

            if ($loadedBeforeConsumption) {
                ++$blocked;
                expect(Observation::$values)->toBe([], 'source availability delay: ' . $availableOnAttempt)
                    ->and(Observation::$constructed)->toBe(0)
                    ->and(Observation::$invoked)->toBe(0)
                    ->and($failure)->not->toBeNull();
            } elseif (!class_exists(__NAMESPACE__ . '\\' . $source, false)) {
                ++$unavailable;
                expect($failure)->toBeNull()
                    ->and(Observation::$values)->toBe(['untrusted'])
                    ->and(Observation::$constructed)->toBe(1)
                    ->and(Observation::$invoked)->toBe(1);
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }
    expect($blocked)->toBeGreaterThan(0)->and($unavailable)->toBeGreaterThan(0);
})->with(['same parameter' => false, 'later parameter' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Reveal
{
    public function __construct(string $class, bool $source = true)
    {
        class_alias($source ? EntryId::class : Metadata::class, $class);
    }
}

final class RevealHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        Observation::$handlerArguments[] = func_num_args();
        return $value;
    }
}

test('an attribute constructor can reveal a source without exposing mapped data to pending handlers', function (bool $uncached, bool $missingMetadataFirst): void {
    $source = 'ConstructorSource_' . bin2hex(random_bytes(5));
    $target = target(sprintf(
        '#[%s%s("trusted"), Reveal(%s::class), \\Componenta\\DI\\Attribute\\Transform(OBSERVER)] string $role',
        $missingMetadataFirst ? 'UnavailableMetadata, ' : '',
        $source,
        $source,
    ));
    $container = builder($uncached)->addAttributeDefinition(new AttributeDefinition(
        Reveal::class,
        new RevealHandler(),
        [ValueTransformer::class],
    ))->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(Observation::$handlerArguments)->toBe([])
        ->and(Observation::$values)->toBe([])
        ->and(Observation::$constructed)->toBe(0)
        ->and(Observation::$invoked)->toBe(0);
})->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['source first' => false, 'unavailable metadata first' => true]);

test('unrelated late metadata preserves custom handler arguments and ordinary mapped values', function (bool $uncached): void {
    $metadata = 'NeutralMetadata_' . bin2hex(random_bytes(5));
    $target = target(sprintf(
        '#[%s, Reveal(%s::class, false), \\Componenta\\DI\\Attribute\\Transform(OBSERVER)] string $role',
        $metadata,
        $metadata,
    ));
    $container = builder($uncached)->addAttributeDefinition(new AttributeDefinition(
        Reveal::class,
        new RevealHandler(),
        [ValueTransformer::class],
    ))->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    $container->call($target, [ServerRequestInterface::class => $request]);

    expect(class_exists(__NAMESPACE__ . '\\' . $metadata, false))->toBeTrue()
        ->and(Observation::$handlerArguments)->toBe([5])
        ->and(Observation::$values)->toBe(['untrusted'])
        ->and(Observation::$constructed)->toBe(1)
        ->and(Observation::$invoked)->toBe(1);
})->with(['cached plans' => false, 'non-memoized plans' => true]);

final class RevealingDependency
{
    public function __construct()
    {
        ++Observation::$dependencies;
        class_alias(EntryId::class, Observation::$source);
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
        $value = $context->resolved[0] ?? null;
        if (!is_string($value)) {
            throw new \LogicException('Expected the callback semantic argument.');
        }
        Observation::$suffixValues[] = $value;
        return [$target->position, 'observed'];
    }
}

function observeWithDependency(string $value, RevealingDependency $dependency, string $suffixProbe): string
{
    return observe($value);
}

define(__NAMESPACE__ . '\\DEPENDENT_OBSERVER', observeWithDependency(...));

test('callback dependency discovery stops later suffix consumers and the callback before accepting mapped data', function (bool $uncached): void {
    $source = 'DependencySource_' . bin2hex(random_bytes(5));
    Observation::$source = __NAMESPACE__ . '\\' . $source;
    $target = target(sprintf(
        '#[%s("trusted"), \\Componenta\\DI\\Attribute\\Transform(DEPENDENT_OBSERVER)] string $role',
        $source,
    ));
    $container = builder($uncached)->addParameterResolver(new SuffixObserver(), 2000)->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(Observation::$dependencies)->toBe(1)
        ->and(Observation::$suffixValues)->toBe([])
        ->and(Observation::$values)->toBe([])
        ->and(Observation::$constructed)->toBe(0)
        ->and(Observation::$invoked)->toBe(0);

    $container->call($target, [ServerRequestInterface::class => $request->withQueryParams([])]);

    expect(Observation::$dependencies)->toBe(1)
        ->and(Observation::$suffixValues)->toBe(['trusted value'])
        ->and(Observation::$values)->toBe(['trusted value'])
        ->and(Observation::$constructed)->toBe(1)
        ->and(Observation::$invoked)->toBe(1);
})->with(['cached plans' => false, 'non-memoized plans' => true]);

function revealWhileObserving(string $value): string
{
    Observation::$values[] = $value;
    class_alias(EntryId::class, Observation::$source);
    return $value;
}

define(__NAMESPACE__ . '\\DISCOVERING_OBSERVER', revealWhileObserving(...));

test('source discovery inside an already running callback does not replay or roll back its side effects', function (bool $uncached): void {
    $source = 'CallbackSource_' . bin2hex(random_bytes(5));
    Observation::$source = __NAMESPACE__ . '\\' . $source;
    $target = target(sprintf(
        '#[%s("trusted"), \\Componenta\\DI\\Attribute\\Transform(DISCOVERING_OBSERVER)] string $role',
        $source,
    ));
    $container = builder($uncached)->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);

    expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
        ->toThrow(AttributeCompositionException::class)
        ->and(Observation::$values)->toBe(['untrusted'])
        ->and(Observation::$constructed)->toBe(0)
        ->and(Observation::$invoked)->toBe(0);
})->with(['cached plans' => false, 'non-memoized plans' => true]);
