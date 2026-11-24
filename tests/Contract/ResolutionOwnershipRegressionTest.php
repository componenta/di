<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ResolutionOwnershipRegression;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use ReflectionClass;

final class Routes
{
    public static function original(): object
    {
        return (object) ['value' => 'original'];
    }

    public static function replacement(): object
    {
        return (object) ['value' => 'replacement'];
    }

    public static function callback(): string
    {
        return 'loaded';
    }
}

final class Configured
{
    /** @param array<array-key,mixed> $arguments */
    public function __construct(public array $arguments) {}
}

#[Attribute(Attribute::TARGET_ALL)]
class GenericMarker {}

#[Attribute(Attribute::TARGET_ALL)]
final class SpecificMarker extends GenericMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final class PeerRuleMarker {}

test('an unloaded attribute is checked against all definitions once it is declared', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $specific = new AttributeDefinition(SpecificMarker::class);
    $registry->register($specific);
    $registry->register(new AttributeDefinition(GenericMarker::class));

    /** @var class-string $name */
    $name = __NAMESPACE__ . '\\PendingMarker_' . bin2hex(random_bytes(6));
    $attempts = 0;
    $loader = static function (string $class) use ($name, &$attempts): void {
        if ($class === $name && ++$attempts === 2) {
            class_alias(SpecificMarker::class, $name);
        }
    };
    spl_autoload_register($loader);
    try {
        expect($registry->definition($name))->toBe($specific)->and($attempts)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('composition rechecks a peer attribute declared by a custom rule', function (): void {
    $suffix = bin2hex(random_bytes(6));
    $peer = 'LatePeer_' . $suffix;
    $target = 'Target_' . $suffix;
    eval(sprintf('namespace %s; #[PeerRuleMarker, %s] final class %s {}', __NAMESPACE__, $peer, $target));
    /** @var class-string $class */
    $class = __NAMESPACE__ . '\\' . $target;
    $rule = new class (__NAMESPACE__ . '\\' . $peer) implements AttributeCompositionRuleInterface {
        public int $calls = 0;

        public function __construct(private readonly string $peer) {}

        public function validate(AttributeUsage $attribute, AttributeSet $set): void
        {
            ++$this->calls;
            if (!class_exists($this->peer, false)) {
                class_alias(SpecificMarker::class, $this->peer);
            }
            if (!$set->has(SpecificMarker::class)) {
                throw new AttributeCompositionException('The rule requires the peer attribute.');
            }
        }
    };
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PeerRuleMarker::class, rules: [$rule]));
    $registry->register(new AttributeDefinition(SpecificMarker::class));

    $plan = (new AttributePlanBuilder($registry))->build(new ReflectionClass($class));

    expect($plan->attributes(SpecificMarker::class))->toHaveCount(1)
        ->and($rule->calls)->toBe(2);
});

test('factory and prepared callable routes retain the selected method', function (): void {
    $method = 'original';
    $route = [Routes::class, &$method];
    $definition = new FactoryDefinition($route);
    $callable = new PreparedCallable($route);
    $container = (new ContainerBuilder())
        ->addFactory('raw', $route)
        ->addDefinition('definition', $definition)
        ->build();

    $method = 'replacement';
    $expected = (object) ['value' => 'original'];
    expect($container->make('raw'))->toEqual($expected)
        ->and($container->make('definition'))->toEqual($expected)
        ->and($callable())->toEqual($expected);
});

test('configured arguments retain both their values and their next append key', function (): void {
    $value = 'original';
    $arguments = ['value' => &$value, 15 => 'removed'];
    unset($arguments[15]);
    $definition = ClassDefinition::create(Configured::class)->constructor(['arguments' => &$arguments]);
    $value = 'replacement';
    $arguments = ['another'];
    $container = (new ContainerBuilder())->addDefinition('configured', $definition)->build();

    $entry = $container->make('configured');
    Assert::assertInstanceOf(Configured::class, $entry);
    $entry->arguments[] = 'appended';
    expect($entry->arguments)->toBe(['value' => 'original', 16 => 'appended']);
});

test('request transformations own changed slots and retain the next append key', function (): void {
    $value = '5';
    $caster = 'int';
    $mapper = new MapRequest(cast: ['value' => &$caster]);
    $mapper->provider = new TestCasterProvider();
    $caster = 'missing';
    $input = ['value' => &$value, 11 => 'removed'];
    unset($input[11]);

    $result = $mapper->transform($input);
    $result[] = 'appended';
    expect($value)->toBe('5')->and($result)->toBe(['value' => 5, 12 => 'appended']);
});

test('recursive values obey the configured request conflict policy', function (): void {
    $first = [];
    $first['self'] = &$first;
    $second = [];
    $second['self'] = &$second;
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['key' => $first])->withQueryParams(['key' => $second]);
    $sources = [RequestDataSource::Payload, RequestDataSource::Query];

    expect(array_keys((new MapRequest($sources, conflictPolicy: RequestDataConflictPolicy::FirstWins))->extract($request)))
        ->toBe(['key'])
        ->and(fn() => (new MapRequest($sources))->extract($request))
        ->toThrow(RequestDataConflictException::class, 'different values');
});

test('parameter resolution retains accepted values and positions across callable autoloading', function (bool $variadic): void {
    $resolver = new class () implements ParameterResolverInterface {
        public int $position = 0;
        public mixed $value;

        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'selected';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [&$this->position, &$this->value];
        }
    };
    $consumer = $variadic
        ? static fn(callable ...$selected): array => array_map(static fn(callable $callback): mixed => $callback(), $selected)
        : static fn(callable $selected): mixed => $selected();
    $container = (new ContainerBuilder())->addParameterResolver($resolver, 2000)->build();
    $name = __NAMESPACE__ . '\\PendingCallable_' . bin2hex(random_bytes(6));
    $tail = Routes::callback(...);
    $resolver->value = $variadic ? [$name . '::callback', &$tail] : $name . '::callback';
    $loader = static function (string $class) use ($name, $resolver, &$tail): void {
        if ($class === $name) {
            class_alias(Routes::class, $name);
            $resolver->position = 9;
            $resolver->value = 42;
            $tail = 42;
        }
    };
    spl_autoload_register($loader);
    try {
        expect($container->call($consumer))->toBe($variadic ? ['loaded', 'loaded'] : 'loaded');
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['single' => false, 'variadic' => true]);
