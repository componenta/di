<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\IndexedPlanProviderInterface;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\Compile\PlanProviderInterface;
use Componenta\DI\Resolver\AttributeDrivenResolverInterface;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Fixture\TypedParameters;

use function Componenta\DI\Tests\Fixture\typedParam;

function recordingParamResolver(?array $returnFor = null): ParameterResolverInterface
{
    return new class ($returnFor) implements ParameterResolverInterface {
        public int $calls = 0;

        public function __construct(private ?array $returnFor) {}

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            $this->calls++;
            return $this->returnFor;
        }
    };
}

function attributeDrivenResolver(): ParameterResolverInterface&AttributeDrivenResolverInterface
{
    return new class () implements ParameterResolverInterface, AttributeDrivenResolverInterface {
        public int $calls = 0;

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            $this->calls++;
            return null;
        }
    };
}

function compiledOverrideResolver(string $kind, mixed $value): ParameterResolverInterface&AttributeMatcherInterface
{
    return new class ($kind, $value) implements ParameterResolverInterface, AttributeMatcherInterface {
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

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            $this->calls++;
            return [$parameter->getPosition(), $this->value];
        }
    };
}

function nullingCompiledResolver(string $kind): ParameterResolverInterface&AttributeMatcherInterface
{
    return new class ($kind) implements ParameterResolverInterface, AttributeMatcherInterface {
        public int $calls = 0;

        public function __construct(private string $kind) {}

        public function planKind(): string
        {
            return $this->kind;
        }

        public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
        {
            return $this->kind;
        }

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            $this->calls++;
            return null;
        }
    };
}

describe('Parameter\\ParametersResolver', function () {
    it('tries resolvers in priority order and returns the first non-null result', function () {
        $first = recordingParamResolver(returnFor: null);
        $second = recordingParamResolver(returnFor: [0, 'from-second']);
        $third = recordingParamResolver(returnFor: [0, 'from-third']);
        $chain = new ParametersResolver($first, $second, $third);

        $result = $chain->resolveParameter(typedParam('untyped', 0));

        expect($result)->toBe([0, 'from-second'])
            ->and($first->calls)->toBe(1)
            ->and($second->calls)->toBe(1)
            ->and($third->calls)->toBe(0);
    });

    it('throws ResolutionException when no resolver produces a value', function () {
        $chain = new ParametersResolver(recordingParamResolver(returnFor: null));

        expect(fn () => $chain->resolveParameter(typedParam('untyped', 0)))
            ->toThrow(ResolutionException::class);
    });

    it('short-circuits AttributeDrivenResolverInterface when the parameter has no attributes', function () {
        $attributeDriven = attributeDrivenResolver();
        $tail = recordingParamResolver(returnFor: [0, 'tail']);
        $chain = new ParametersResolver($attributeDriven, $tail);

        $chain->resolveParameter(typedParam('untyped', 0));

        expect($attributeDriven->calls)->toBe(0)
            ->and($tail->calls)->toBe(1);
    });

    it('resolveAll returns values indexed by parameter position', function () {
        $chain = new ParametersResolver(new ArrayResolver(), new DefaultValueResolver(), new NullableResolver());
        $parameters = (new ReflectionMethod(
            Componenta\DI\Tests\Fixture\TypedParameters::class,
            'withDefaults'
        ))->getParameters();

        $result = $chain->resolve($parameters, ['sort' => 'desc']);

        expect($result)->toBe([0 => 1, 1 => 'desc']);
    });

    it('add() appends a resolver and affects subsequent resolutions', function () {
        $chain = new ParametersResolver();
        expect(fn () => $chain->resolveParameter(typedParam('untyped', 0)))
            ->toThrow(ResolutionException::class);

        $chain->add(recordingParamResolver(returnFor: [0, 'late-bound']));

        expect($chain->resolveParameter(typedParam('untyped', 0)))->toBe([0, 'late-bound']);
    });

    it('exposes a defensive clone of the resolver list via the `resolvers` property', function () {
        $chain = new ParametersResolver(recordingParamResolver(returnFor: null));
        $snapshot = $chain->resolvers;

        $chain->add(recordingParamResolver(returnFor: [0, 'x']));

        expect($snapshot->count())->toBe(1)
            ->and($chain->resolvers->count())->toBe(2);
    });

    it('passes provided and already-resolved parameters to each resolver', function () {
        $capturing = new class () implements ParameterResolverInterface {
            public array $provided = [];
            public array $resolved = [];

            public function resolveParameter(
                ReflectionParameter $parameter,
                array $providedParameters = [],
                array $resolvedParameters = [],
            ): ?array {
                $this->provided = $providedParameters;
                $this->resolved = $resolvedParameters;
                return [$parameter->getPosition(), 'captured'];
            }
        };
        $chain = new ParametersResolver($capturing);

        $chain->resolveParameter(typedParam('untyped', 0), ['a' => 1], [5 => 'earlier']);

        expect($capturing->provided)->toBe(['a' => 1])
            ->and($capturing->resolved)->toBe([5 => 'earlier']);
    });

    it('lets object provided values override compiled plans through ArrayTypedResolver', function () {
        $planned = compiledOverrideResolver('componenta.di.planned', new stdClass());
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $chain = new ParametersResolver(new ArrayResolver(), new ArrayTypedResolver(), $planned);
        $chain->setCompiledPlans([
            TypedParameters::class => [
                'byUnion' => [0 => 'componenta.di.planned'],
            ],
        ], $dispatcher);

        $provided = new stdClass();
        $result = $chain->resolveParameter(typedParam('byUnion', 0), ['context' => $provided]);

        expect($result)->toBe([0, $provided])
            ->and($planned->calls)->toBe(0);
    });

    it('lets name and position provided values override compiled plans', function () {
        $planned = compiledOverrideResolver('componenta.di.planned', 'planned');
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $chain = new ParametersResolver(new ArrayResolver(), $planned);
        $chain->setCompiledPlans([
            TypedParameters::class => [
                'typedString' => [0 => 'componenta.di.planned'],
            ],
        ], $dispatcher);

        expect($chain->resolveParameter(typedParam('typedString', 0), ['name' => 'by-name']))
            ->toBe([0, 'by-name'])
            ->and($chain->resolveParameter(typedParam('typedString', 0), [0 => 'by-position']))
            ->toBe([0, 'by-position'])
            ->and($planned->calls)->toBe(0);
    });

    it('lets provided type keys override compiled plans for union parameters', function () {
        $planned = compiledOverrideResolver('componenta.di.planned', new stdClass());
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $chain = new ParametersResolver(new ArrayResolver(), new ArrayTypedResolver(), $planned);
        $chain->setCompiledPlans([
            TypedParameters::class => [
                'byUnion' => [0 => 'componenta.di.planned'],
            ],
        ], $dispatcher);

        $provided = new stdClass();
        $result = $chain->resolveParameter(typedParam('byUnion', 0), [stdClass::class => $provided]);

        expect($result)->toBe([0, $provided])
            ->and($planned->calls)->toBe(0);
    });

    it('falls back to the resolver chain when a compiled resolver returns null', function () {
        $planned = nullingCompiledResolver('componenta.di.nulling');
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $chain = new ParametersResolver(new DefaultValueResolver());
        $chain->setCompiledPlans([
            TypedParameters::class => [
                'withDefaults' => [0 => 'componenta.di.nulling'],
            ],
        ], $dispatcher);

        expect($chain->resolveParameter(typedParam('withDefaults', 0)))
            ->toBe([0, 1])
            ->and($planned->calls)->toBe(1);
    });

    it('asks plan providers for the targeted method plan without materializing all plans', function () {
        $planned = compiledOverrideResolver('componenta.di.planned', 'planned');
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $provider = new class () implements IndexedPlanProviderInterface {
            public int $plansCalls = 0;
            public int $parameterPlanCalls = 0;

            public function plans(): array
            {
                $this->plansCalls++;

                return [];
            }

            public function parameterPlan(string $class, string $method): ?array
            {
                $this->parameterPlanCalls++;

                return [0 => 'componenta.di.planned'];
            }

            public function propertyPlan(string $class, string $property): string|array|null
            {
                return null;
            }
        };

        $chain = new ParametersResolver($planned);
        $chain->setCompiledPlanProvider($provider, $dispatcher);

        $result = $chain->resolveParameter(typedParam('typedString', 0));

        expect($result)->toBe([0, 'planned'])
            ->and($provider->plansCalls)->toBe(0)
            ->and($provider->parameterPlanCalls)->toBe(1);
    });

    it('keeps legacy plan providers on the full-map fallback path', function () {
        $planned = compiledOverrideResolver('componenta.di.planned', 'planned');
        $dispatcher = new PlanDispatcher();
        $dispatcher->bind($planned);

        $provider = new class () implements PlanProviderInterface {
            public int $plansCalls = 0;

            public function plans(): array
            {
                $this->plansCalls++;

                return [
                    'param' => [
                        TypedParameters::class => [
                            'typedString' => [0 => 'componenta.di.planned'],
                        ],
                    ],
                ];
            }
        };

        $chain = new ParametersResolver($planned);
        $chain->setCompiledPlanProvider($provider, $dispatcher);

        $result = $chain->resolveParameter(typedParam('typedString', 0));

        expect($result)->toBe([0, 'planned'])
            ->and($provider->plansCalls)->toBe(1);
    });
});
