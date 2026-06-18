<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\ParameterPlanResolverInterface;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Componenta\DI\Tests\Fixture\TypedProperties;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function paramOnlyResolver(string $kind, mixed $returnValue): ParameterResolverInterface&AttributeMatcherInterface
{
    return new class ($kind, $returnValue) implements ParameterResolverInterface, AttributeMatcherInterface {
        public int $calls = 0;

        public function __construct(private string $kind, private mixed $returnValue) {}

        public function planKind(): string { return $this->kind; }

        public function claimTarget(ReflectionParameter|ReflectionProperty $t): ?string
        {
            return $this->kind;
        }

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            $this->calls++;
            return $this->returnValue;
        }
    };
}

function payloadParamResolver(string $kind): ParameterResolverInterface&AttributeMatcherInterface&ParameterPlanResolverInterface
{
    return new class ($kind) implements ParameterResolverInterface, AttributeMatcherInterface, ParameterPlanResolverInterface {
        public int $regularCalls = 0;
        public int $payloadCalls = 0;
        public mixed $receivedPayload = null;

        public function __construct(private string $kind) {}

        public function planKind(): string { return $this->kind; }

        public function claimTarget(ReflectionParameter|ReflectionProperty $t): ?string
        {
            return $this->kind;
        }

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            ++$this->regularCalls;

            return [$parameter->getPosition(), 'regular'];
        }

        public function resolveParameterPlan(
            ReflectionParameter $parameter,
            mixed $payload,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            ++$this->payloadCalls;
            $this->receivedPayload = $payload;

            return [$parameter->getPosition(), 'payload'];
        }
    };
}

function bothResolver(string $kind): ParameterResolverInterface&PropertyResolverInterface&AttributeMatcherInterface
{
    return new class ($kind) implements
        ParameterResolverInterface,
        PropertyResolverInterface,
        AttributeMatcherInterface
    {
        public int $paramCalls = 0;
        public int $propCalls = 0;

        public function __construct(private string $kind) {}

        public function planKind(): string { return $this->kind; }

        public function claimTarget(ReflectionParameter|ReflectionProperty $t): ?string
        {
            return $this->kind;
        }

        public function resolveParameter(
            ReflectionParameter $parameter,
            array $providedParameters = [],
            array $resolvedParameters = [],
        ): ?array {
            $this->paramCalls++;
            return [$parameter->getPosition(), 'from-param'];
        }

        public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
        {
            $this->propCalls++;
            return [$property, 'from-prop'];
        }
    };
}

describe('Compile\\PlanDispatcher', function () {
    describe('kindMap()', function () {
        it('exports parameter and property plan kinds as resolver classes', function () {
            $param = paramOnlyResolver('componenta.di.param', [0, 'ok']);
            $both = bothResolver('componenta.di.both');

            $map = PlanDispatcher::kindMap([$param, $both], [$both]);

            expect($map['param'])
                ->toBe([
                    'componenta.di.param' => $param::class,
                    'componenta.di.both' => $both::class,
                ])
                ->and($map['prop'])
                ->toBe([
                    'componenta.di.both' => $both::class,
                ]);
        });
    });

    describe('fromKindMap()', function () {
        it('rehydrates a dispatcher from cached resolver classes', function () {
            $resolver = payloadParamResolver('componenta.di.payload');
            $dispatcher = PlanDispatcher::fromKindMap(
                ['param' => ['componenta.di.payload' => $resolver::class]],
                [$resolver],
                [],
            );

            expect($dispatcher)->toBeInstanceOf(PlanDispatcher::class);

            $result = $dispatcher->dispatchParameter(
                ['kind' => 'componenta.di.payload', 'payload' => ['type' => stdClass::class]],
                typedParam('untyped', 0),
                [],
                [],
            );

            expect($result)->toBe([0, 'payload'])
                ->and($resolver->payloadCalls)->toBe(1);
        });

        it('returns null when the cached map references a missing resolver class', function () {
            expect(PlanDispatcher::fromKindMap(
                ['param' => ['componenta.di.missing' => stdClass::class]],
                [],
                [],
            ))->toBeNull();
        });
    });

    describe('bind()', function () {
        it('registers a parameter resolver under its planKind for parameter dispatch', function () {
            $dispatcher = new PlanDispatcher();
            $resolver = paramOnlyResolver('componenta.di.param', [0, 'ok']);

            $dispatcher->bind($resolver);

            expect($dispatcher->hasParameterKind('componenta.di.param'))->toBeTrue()
                ->and($dispatcher->hasPropertyKind('componenta.di.param'))->toBeFalse();
        });

        it('binds a resolver that implements both interfaces under both sides', function () {
            $dispatcher = new PlanDispatcher();
            $resolver = bothResolver('componenta.di.both');

            $dispatcher->bind($resolver);

            expect($dispatcher->hasParameterKind('componenta.di.both'))->toBeTrue()
                ->and($dispatcher->hasPropertyKind('componenta.di.both'))->toBeTrue();
        });
    });

    describe('dispatchParameter()', function () {
        it('routes to the bound resolver and returns its value', function () {
            $dispatcher = new PlanDispatcher();
            $resolver = paramOnlyResolver('componenta.di.param', [1, 'value']);
            $dispatcher->bind($resolver);

            $result = $dispatcher->dispatchParameter(
                'componenta.di.param',
                typedParam('untyped', 1),
                [],
                [],
            );

            expect($result)->toBe([1, 'value'])
                ->and($resolver->calls)->toBe(1);
        });

        it('returns null for an unknown plan kind without calling any resolver', function () {
            $dispatcher = new PlanDispatcher();
            $resolver = paramOnlyResolver('componenta.di.known', [0, 'x']);
            $dispatcher->bind($resolver);

            expect($dispatcher->dispatchParameter('componenta.di.unknown', typedParam('untyped', 0), [], []))
                ->toBeNull()
                ->and($resolver->calls)->toBe(0);
        });

        it('returns null when the resolver itself returns null', function () {
            $dispatcher = new PlanDispatcher();
            $dispatcher->bind(paramOnlyResolver('componenta.di.nulling', null));

            expect($dispatcher->dispatchParameter('componenta.di.nulling', typedParam('untyped', 0), [], []))
                ->toBeNull();
        });

        it('routes payload entries to payload-aware parameter resolvers', function () {
            $dispatcher = new PlanDispatcher();
            $resolver = payloadParamResolver('componenta.di.payload');
            $dispatcher->bind($resolver);

            $result = $dispatcher->dispatchParameter(
                ['kind' => 'componenta.di.payload', 'payload' => ['type' => stdClass::class]],
                typedParam('untyped', 0),
                [],
                [],
            );

            expect($result)->toBe([0, 'payload'])
                ->and($resolver->payloadCalls)->toBe(1)
                ->and($resolver->regularCalls)->toBe(0)
                ->and($resolver->receivedPayload)->toBe(['type' => stdClass::class]);
        });
    });

    describe('dispatchProperty()', function () {
        it('routes to the bound resolver', function () {
            $dispatcher = new PlanDispatcher();
            $resolver = bothResolver('componenta.di.both');
            $dispatcher->bind($resolver);

            $property = typedProperty(TypedProperties::class, 'name');
            $result = $dispatcher->dispatchProperty('componenta.di.both', $property, []);

            expect($result)->toBe([$property, 'from-prop'])
                ->and($resolver->propCalls)->toBe(1);
        });

        it('returns null for an unknown plan kind', function () {
            $dispatcher = new PlanDispatcher();

            expect($dispatcher->dispatchProperty('componenta.di.nothing', typedProperty(TypedProperties::class, 'name'), []))
                ->toBeNull();
        });
    });

    it('overwrites an earlier binding for the same plan kind', function () {
        $dispatcher = new PlanDispatcher();
        $first = paramOnlyResolver('componenta.di.dup', [0, 'first']);
        $second = paramOnlyResolver('componenta.di.dup', [0, 'second']);
        $dispatcher->bind($first);
        $dispatcher->bind($second);

        $result = $dispatcher->dispatchParameter('componenta.di.dup', typedParam('untyped', 0), [], []);

        expect($result)->toBe([0, 'second'])
            ->and($first->calls)->toBe(0)
            ->and($second->calls)->toBe(1);
    });
});
