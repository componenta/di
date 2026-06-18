<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Tests\Fixture\CastTargets;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function intCaster(): CasterInterface
{
    return new class () implements CasterInterface {
        public string $name { get => 'int'; }

        public function cast(mixed $value): mixed
        {
            return (int) $value;
        }
    };
}

function casterProvider(array $casters): CasterProviderInterface
{
    return new class ($casters) implements CasterProviderInterface {
        public function __construct(private array $casters) {}

        public function provide(string $name): ?CasterInterface
        {
            return $this->casters[$name] ?? null;
        }
    };
}

describe('Resolver\\CastableResolver', function () {
    describe('property resolution', function () {
        it('returns null for unattributed properties', function () {
            $resolver = new CastableResolver(casterProvider([]));

            expect($resolver->resolveProperty(typedProperty(CastTargets::class, 'unattributed')))
                ->toBeNull();
        });

        it('casts the value from context via the named caster', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));
            $property = typedProperty(CastTargets::class, 'age');

            $result = $resolver->resolveProperty($property, ['age' => '42']);

            expect($result)->toBe([$property, 42]);
        });

        it('uses the attribute default when context has no entry for the property', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            // 'default-raw' is passed through (int)(string "default-raw") -> 0
            expect($resolver->resolveProperty(typedProperty(CastTargets::class, 'withDefault'))[1])
                ->toBe(0);
        });

        it('throws ResolutionException when neither context nor default is available', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            expect(fn () => $resolver->resolveProperty(typedProperty(CastTargets::class, 'age')))
                ->toThrow(ResolutionException::class, 'missing context key');
        });

        it('throws ResolutionException when the caster name is not registered', function () {
            $resolver = new CastableResolver(casterProvider([])); // no 'int' caster

            expect(fn () => $resolver->resolveProperty(typedProperty(CastTargets::class, 'age'), ['age' => '1']))
                ->toThrow(ResolutionException::class, 'caster "int" is not registered');
        });

        it('wraps foreign caster exceptions into ResolutionException', function () {
            $boom = new RuntimeException('cast boom');
            $failing = new class ($boom) implements CasterInterface {
                public string $name { get => 'int'; }

                public function __construct(private Throwable $error) {}

                public function cast(mixed $value): mixed
                {
                    throw $this->error;
                }
            };
            $resolver = new CastableResolver(casterProvider(['int' => $failing]));

            try {
                $resolver->resolveProperty(typedProperty(CastTargets::class, 'age'), ['age' => '1']);
            } catch (ResolutionException $e) {
                expect($e->getPrevious())->toBe($boom);
                return;
            }

            self::fail('expected ResolutionException');
        });

        it('compiles and resolves a property cast payload', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));
            $property = typedProperty(CastTargets::class, 'withDefault');

            $payload = $resolver->compilePayload($property);

            expect($payload)->toBe([
                'name' => 'withDefault',
                'cast' => 'int',
                'hasDefault' => true,
                'default' => 'default-raw',
                'allowsNull' => false,
                'hasParameterDefault' => false,
                'parameterDefault' => null,
            ])->and($resolver->resolvePropertyPlan($property, $payload)[1])->toBe(0);
        });
    });

    describe('parameter resolution', function () {
        it('returns null for unattributed parameters', function () {
            $resolver = new CastableResolver(casterProvider([]));

            expect($resolver->resolveParameter(typedParam('byParameters', 5, CastTargets::class)))
                ->toBeNull();
        });

        it('casts a provided value via the caster', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            expect($resolver->resolveParameter(typedParam('byParameters', 0, CastTargets::class), ['age' => '7']))
                ->toBe([0, 7]);
        });

        it('uses the attribute default when no provided value exists', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            // 'attr-default' -> (int) 'attr-default' -> 0
            expect($resolver->resolveParameter(typedParam('byParameters', 1, CastTargets::class))[1])
                ->toBe(0);
        });

        it('falls back to null when the parameter allows null and no value/default is present', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            expect($resolver->resolveParameter(typedParam('byParameters', 2, CastTargets::class)))
                ->toBe([2, null]);
        });

        it('falls back to the parameter default when allowed', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            expect($resolver->resolveParameter(typedParam('byParameters', 3, CastTargets::class)))
                ->toBe([3, 42]);
        });

        it('throws ResolutionException when parameter has no fallback path', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));

            // requiredOnly() has a single Cast parameter that's not nullable
            // and has no declared default - no fallback path available.
            expect(fn () => $resolver->resolveParameter(typedParam('requiredOnly', 0, CastTargets::class)))
                ->toThrow(ResolutionException::class);
        });

        it('compiles and resolves a parameter cast payload', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));
            $parameter = typedParam('byParameters', 1, CastTargets::class);

            $payload = $resolver->compilePayload($parameter);

            expect($payload)->toBe([
                'name' => 'withAttrDefault',
                'cast' => 'int',
                'hasDefault' => true,
                'default' => 'attr-default',
                'allowsNull' => false,
                'hasParameterDefault' => false,
                'parameterDefault' => null,
            ])->and($resolver->resolveParameterPlan($parameter, $payload)[1])->toBe(0);
        });

        it('keeps nullable and declared-default parameter fallbacks in cast payload mode', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));
            $nullable = typedParam('byParameters', 2, CastTargets::class);
            $defaulted = typedParam('byParameters', 3, CastTargets::class);

            expect($resolver->resolveParameterPlan($nullable, $resolver->compilePayload($nullable)))
                ->toBe([2, null])
                ->and($resolver->resolveParameterPlan($defaulted, $resolver->compilePayload($defaulted)))
                ->toBe([3, 42]);
        });

        it('falls back to the attribute resolver when the cast payload is invalid', function () {
            $resolver = new CastableResolver(casterProvider(['int' => intCaster()]));
            $parameter = typedParam('byParameters', 0, CastTargets::class);

            expect($resolver->resolveParameterPlan($parameter, ['invalid' => true], ['age' => '7']))
                ->toBe([0, 7]);
        });
    });
});
