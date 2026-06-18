<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Resolver\AttributeDrivenResolverInterface;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\IndexedPlanProviderInterface;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\Resolver\Property\ArrayResolver;
use Componenta\DI\Resolver\Property\PropertiesResolver;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\DI\Tests\Fixture\TypedProperties;

use function Componenta\DI\Tests\Fixture\typedProperty;

function recordingPropertyResolver(?array $returnFor = null): PropertyResolverInterface
{
    return new class ($returnFor) implements PropertyResolverInterface {
        public int $calls = 0;

        public function __construct(private ?array $returnFor) {}

        public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
        {
            $this->calls++;
            return $this->returnFor;
        }
    };
}

function attributeDrivenPropertyResolver(): PropertyResolverInterface&AttributeDrivenResolverInterface
{
    return new class () implements PropertyResolverInterface, AttributeDrivenResolverInterface {
        public int $calls = 0;

        public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
        {
            $this->calls++;
            return null;
        }
    };
}

function compiledPropertyResolver(string $kind, mixed $value): PropertyResolverInterface&AttributeMatcherInterface
{
    return new class ($kind, $value) implements PropertyResolverInterface, AttributeMatcherInterface {
        public int $calls = 0;

        public function __construct(private string $kind, private mixed $value) {}

        public function planKind(): string
        {
            return $this->kind;
        }

        public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
        {
            return $this->kind;
        }

        public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
        {
            $this->calls++;

            return [$property, $this->value];
        }
    };
}

describe('Property\\PropertiesResolver', function () {
    it('returns null when no resolver produces a value (does not throw, unlike parameters)', function () {
        $chain = new PropertiesResolver(recordingPropertyResolver(returnFor: null));

        expect($chain->resolveProperty(typedProperty(TypedProperties::class, 'plain')))
            ->toBeNull();
    });

    it('short-circuits to the first non-null result', function () {
        $property = typedProperty(TypedProperties::class, 'plain');
        $first = recordingPropertyResolver(returnFor: null);
        $second = recordingPropertyResolver(returnFor: [$property, 'from-second']);
        $third = recordingPropertyResolver(returnFor: [$property, 'from-third']);
        $chain = new PropertiesResolver($first, $second, $third);

        $result = $chain->resolveProperty($property);

        expect($result)->toBe([$property, 'from-second'])
            ->and($third->calls)->toBe(0);
    });

    it('skips AttributeDrivenResolverInterface resolvers for properties without any attributes', function () {
        $attributeDriven = attributeDrivenPropertyResolver();
        $tail = recordingPropertyResolver(returnFor: null);
        $chain = new PropertiesResolver($attributeDriven, $tail);

        $chain->resolveProperty(typedProperty(TypedProperties::class, 'plain'));

        expect($attributeDriven->calls)->toBe(0)
            ->and($tail->calls)->toBe(1);
    });

    it('consults AttributeDrivenResolverInterface resolvers for properties with attributes', function () {
        $attributeDriven = attributeDrivenPropertyResolver();
        $chain = new PropertiesResolver($attributeDriven);

        $chain->resolveProperty(typedProperty(TypedProperties::class, 'logger')); // has #[Inject]

        expect($attributeDriven->calls)->toBe(1);
    });

    it('resolve() aggregates results keyed by property name and skips unresolved properties', function () {
        $chain = new PropertiesResolver(new ArrayResolver());
        $properties = [
            typedProperty(TypedProperties::class, 'name'),
            typedProperty(TypedProperties::class, 'count'),
            typedProperty(TypedProperties::class, 'plain'),
        ];

        $result = $chain->resolve($properties, ['name' => 'Bob', 'count' => 3]);

        expect(array_keys($result))->toBe(['name', 'count'])
            ->and($result['name'][1])->toBe('Bob')
            ->and($result['count'][1])->toBe(3);
    });

    it('forwards context to each resolver', function () {
        $capturing = new class () implements PropertyResolverInterface {
            public array $context = [];

            public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
            {
                $this->context = $context;
                return null;
            }
        };
        $chain = new PropertiesResolver($capturing);

        $chain->resolveProperty(typedProperty(TypedProperties::class, 'plain'), ['k' => 'v']);

        expect($capturing->context)->toBe(['k' => 'v']);
    });

    it('exposes a defensive clone of the resolver list via the `resolvers` property', function () {
        $chain = new PropertiesResolver(recordingPropertyResolver(returnFor: null));
        $snapshot = $chain->resolvers;

        $chain->add(recordingPropertyResolver(returnFor: null));

        expect($snapshot->count())->toBe(1)
            ->and($chain->resolvers->count())->toBe(2);
    });

    it('asks plan providers for the targeted property plan without materializing all plans', function () {
        $planned = compiledPropertyResolver('componenta.di.property', 'planned');
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $provider = new class () implements IndexedPlanProviderInterface {
            public int $plansCalls = 0;
            public int $propertyPlanCalls = 0;

            public function plans(): array
            {
                $this->plansCalls++;

                return [];
            }

            public function parameterPlan(string $class, string $method): ?array
            {
                return null;
            }

            public function propertyPlan(string $class, string $property): string|array|null
            {
                $this->propertyPlanCalls++;

                return 'componenta.di.property';
            }
        };

        $chain = new PropertiesResolver($planned);
        $chain->setCompiledPlanProvider($provider, $dispatcher);

        $property = typedProperty(TypedProperties::class, 'logger');
        $result = $chain->resolveProperty($property);

        expect($result)->toBe([$property, 'planned'])
            ->and($provider->plansCalls)->toBe(0)
            ->and($provider->propertyPlanCalls)->toBe(1);
    });
});
