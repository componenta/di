<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\MakeAttributeResolver;
use Componenta\DI\Tests\Fixture\MakeTargets;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function recordingFactory(?callable $impl = null): FactoryInterface
{
    return new class ($impl) implements FactoryInterface {
        public array $calls = [];

        public function __construct(private mixed $impl) {}

        public function make(string $entry, array $params = []): object
        {
            $this->calls[] = [$entry, $params];
            return $this->impl !== null
                ? ($this->impl)($entry, $params)
                : new $entry(...$params);
        }
    };
}

describe('Resolver\\MakeAttributeResolver', function () {
    describe('property resolution', function () {
        it('returns null for properties without #[Make] or #[Proxy]', function () {
            $resolver = new MakeAttributeResolver(recordingFactory());

            expect($resolver->resolveProperty(typedProperty(MakeTargets::class, 'unattributed')))
                ->toBeNull();
        });

        it('derives the entry id from the declared type when #[Make] has no explicit entry', function () {
            $factory = recordingFactory();
            $resolver = new MakeAttributeResolver($factory);
            $property = typedProperty(MakeTargets::class, 'typeDerived');

            $result = $resolver->resolveProperty($property);

            expect($factory->calls)->toBe([[SimpleService::class, []]])
                ->and($result[0])->toBe($property)
                ->and($result[1])->toBeInstanceOf(SimpleService::class);
        });

        it('uses the explicit entry and params from the attribute', function () {
            $factory = recordingFactory();
            $resolver = new MakeAttributeResolver($factory);
            $property = typedProperty(MakeTargets::class, 'explicitWithParams');

            $result = $resolver->resolveProperty($property);

            expect($factory->calls)->toBe([[ServiceWithParam::class, ['value' => 'make-params']]])
                ->and($result[1])->toBeInstanceOf(ServiceWithParam::class)
                ->and($result[1]->value)->toBe('make-params');
        });

        it('wraps the instance in a virtual proxy when #[Proxy] is present', function () {
            $factory = recordingFactory();
            $resolver = new MakeAttributeResolver($factory, proxyFactory: new ProxyFactory());
            $property = typedProperty(MakeTargets::class, 'withProxy');

            $result = $resolver->resolveProperty($property);

            // Factory is not called until the proxy is first accessed
            expect($factory->calls)->toBe([])
                ->and($result[1])->toBeInstanceOf(SimpleService::class);
        });

        it('wraps foreign exceptions into ResolutionException', function () {
            $boom = new RuntimeException('factory boom');
            $resolver = new MakeAttributeResolver(recordingFactory(fn () => throw $boom));

            try {
                $resolver->resolveProperty(typedProperty(MakeTargets::class, 'typeDerived'));
            } catch (ResolutionException $e) {
                expect($e->getPrevious())->toBe($boom);
                return;
            }

            self::fail('expected ResolutionException');
        });
    });

    describe('parameter resolution', function () {
        it('returns null for unattributed parameters', function () {
            $resolver = new MakeAttributeResolver(recordingFactory());

            expect($resolver->resolveParameter(typedParam('byParameters', 2, MakeTargets::class)))
                ->toBeNull();
        });

        it('resolves #[Make] parameter by type-derived entry', function () {
            $factory = recordingFactory();
            $resolver = new MakeAttributeResolver($factory);

            $result = $resolver->resolveParameter(typedParam('byParameters', 0, MakeTargets::class));

            expect($result[0])->toBe(0)
                ->and($result[1])->toBeInstanceOf(SimpleService::class)
                ->and($factory->calls)->toBe([[SimpleService::class, []]]);
        });

        it('passes params from #[Make] at parameter level', function () {
            $factory = recordingFactory();
            $resolver = new MakeAttributeResolver($factory);

            $result = $resolver->resolveParameter(typedParam('byParameters', 1, MakeTargets::class));

            expect($factory->calls)->toBe([[ServiceWithParam::class, ['value' => 'param-make']]])
                ->and($result[1]->value)->toBe('param-make');
        });
    });

    describe('FactoryConfigReader (observed through the resolver)', function () {
        it('reads Make+Proxy into a single config triple', function () {
            $reader = new \Componenta\DI\Resolver\FactoryConfigReader();

            $config = $reader->read(typedProperty(MakeTargets::class, 'withProxy'));

            expect($config)->toBe([
                'entry'  => SimpleService::class,
                'params' => [],
                'proxy'  => true,
            ]);
        });

        it('returns null for a target with neither Make nor Proxy', function () {
            $reader = new \Componenta\DI\Resolver\FactoryConfigReader();

            expect($reader->read(typedProperty(MakeTargets::class, 'unattributed')))
                ->toBeNull();
        });
    });
});
