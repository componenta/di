<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixture/reflection_helpers.php';
require_once __DIR__ . '/../../../Fixture/RequestResolverFixture.php';

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Tests\Fixture\FakeQueryMap;
use Componenta\DI\Tests\Fixture\FakeQueryParam;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;
use Componenta\DI\Tests\Fixture\CookieNullableFixture;
use Componenta\DI\Tests\Fixture\PayloadParamNullableFixture;
use Componenta\DI\Tests\Fixture\PayloadParamPathNullableFixture;
use Componenta\DI\Tests\Fixture\RequestAttributeNullableFixture;
use Componenta\DI\Tests\Fixture\RequestDtoTarget;
use Componenta\DI\Tests\Fixture\RequestTargets;
use Psr\Http\Message\UriInterface;

use function Componenta\DI\Tests\Fixture\typedParam;

function noopCasterProvider(): CasterProviderInterface
{
    return new class () implements CasterProviderInterface {
        public function provide(string $name): ?CasterInterface
        {
            return null;
        }
    };
}

function intCasterProvider(): CasterProviderInterface
{
    $int = new class () implements CasterInterface {
        public string $name { get => 'int'; }

        public function cast(mixed $value): mixed
        {
            return (int) $value;
        }
    };

    return new class ($int) implements CasterProviderInterface {
        public function __construct(private CasterInterface $int) {}

        public function provide(string $name): ?CasterInterface
        {
            return $name === 'int' ? $this->int : null;
        }
    };
}

function hydratingFactory(): FactoryInterface
{
    return new class () implements FactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            return new $entry(...$params);
        }
    };
}

function requestWithQuery(array $query): ServerRequest
{
    $request = new ServerRequest('GET', '/foo?' . http_build_query($query));
    return $request->withQueryParams($query);
}

function requestProvided(ServerRequest $request): array
{
    return RequestParameter::with([], $request);
}

describe('Resolver\\Parameter\\Request\\RequestResolver', function () {
    describe('matchTarget()', function () {
        it('returns the kind for parameters with an extractor attribute', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());

            expect($resolver->claimTarget(typedParam('singleValue', 0, RequestTargets::class)))
                ->toBe(RequestResolver::KIND);
        });

        it('returns the kind for UriInterface-typed parameters', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());

            expect($resolver->claimTarget(typedParam('singleValue', 2, RequestTargets::class)))
                ->toBe(RequestResolver::KIND);
        });

        it('returns null for unrelated parameters', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());

            expect($resolver->claimTarget(typedParam('singleValue', 3, RequestTargets::class)))
                ->toBeNull();
        });
    });

    describe('resolveParameter()', function () {
        it('returns null for unattributed, non-Uri parameters', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());

            expect($resolver->resolveParameter(typedParam('singleValue', 3, RequestTargets::class)))
                ->toBeNull();
        });

        it('extracts a single value via an ExtractorInterface attribute', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $provided = requestProvided(requestWithQuery(['q' => 'hello']));

            $result = $resolver->resolveParameter(
                typedParam('singleValue', 0, RequestTargets::class),
                $provided,
            );

            expect($result)->toBe([0, 'hello']);
        });

        it('applies CastableInterface.cast after extraction', function () {
            $resolver = new RequestResolver(hydratingFactory(), intCasterProvider());
            $provided = requestProvided(requestWithQuery(['page' => '42']));

            $result = $resolver->resolveParameter(
                typedParam('singleValue', 1, RequestTargets::class),
                $provided,
            );

            expect($result)->toBe([1, 42]);
        });

        it('throws when a single-value extractor references an unknown caster', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $provided = requestProvided(requestWithQuery(['page' => '42']));

            expect(fn () => $resolver->resolveParameter(
                typedParam('singleValue', 1, RequestTargets::class),
                $provided,
            ))->toThrow(ResolutionException::class, 'caster "int" is not registered');
        });

        it('resolves a UriInterface parameter from the request', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $request = requestWithQuery(['x' => 'y']);

            $result = $resolver->resolveParameter(
                typedParam('singleValue', 2, RequestTargets::class),
                requestProvided($request),
            );

            expect($result[0])->toBe(2)
                ->and($result[1])->toBeInstanceOf(UriInterface::class);
        });

        it('throws ResolutionException when the request is missing from provided params (attribute path)', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());

            expect(fn () => $resolver->resolveParameter(
                typedParam('singleValue', 0, RequestTargets::class),
            ))->toThrow(ResolutionException::class, 'PSR-7 request is required');
        });

        it('throws ResolutionException when the request is missing (UriInterface path)', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());

            expect(fn () => $resolver->resolveParameter(
                typedParam('singleValue', 2, RequestTargets::class),
            ))->toThrow(ResolutionException::class);
        });

        it('returns the mapped array as-is when the parameter type is array', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $provided = requestProvided(requestWithQuery(['a' => '1', 'b' => '2']));

            $result = $resolver->resolveParameter(
                typedParam('mapperToArray', 0, RequestTargets::class),
                $provided,
            );

            expect($result)->toBe([0, ['a' => '1', 'b' => '2']]);
        });

        it('hydrates a DTO via the factory when the parameter type is a class', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $provided = requestProvided(requestWithQuery(['q' => 'built']));

            $result = $resolver->resolveParameter(
                typedParam('mapperToDto', 0, RequestTargets::class),
                $provided,
            );

            expect($result[1])->toBeInstanceOf(RequestDtoTarget::class)
                ->and($result[1]->q)->toBe('built');
        });

        it('preserves explicit null payload values instead of using the attribute default', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $request = (new ServerRequest('POST', '/'))
                ->withParsedBody(['nickname' => null]);

            $result = $resolver->resolveParameter(
                typedParam('handle', 0, PayloadParamNullableFixture::class),
                requestProvided($request),
            );

            expect($result)->toBe([0, null]);
        });

        it('preserves explicit null payload path values instead of using the attribute default', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $request = (new ServerRequest('POST', '/'))
                ->withParsedBody(['user' => ['profile' => ['nickname' => null]]]);

            $result = $resolver->resolveParameter(
                typedParam('handle', 0, PayloadParamPathNullableFixture::class),
                requestProvided($request),
            );

            expect($result)->toBe([0, null]);
        });

        it('preserves explicit null cookie values instead of using the attribute default', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $request = (new ServerRequest('GET', '/'))
                ->withCookieParams(['session_id' => null]);

            $result = $resolver->resolveParameter(
                typedParam('handle', 0, CookieNullableFixture::class),
                requestProvided($request),
            );

            expect($result)->toBe([0, null]);
        });

        it('preserves explicit null request attributes instead of using the attribute default', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $request = (new ServerRequest('GET', '/'))
                ->withAttribute('nickname', null);

            $result = $resolver->resolveParameter(
                typedParam('handle', 0, RequestAttributeNullableFixture::class),
                requestProvided($request),
            );

            expect($result)->toBe([0, null]);
        });

        it('compiles and resolves an extractor attribute payload', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $parameter = typedParam('singleValue', 0, RequestTargets::class);
            $provided = requestProvided(requestWithQuery(['q' => 'planned']));

            $payload = $resolver->compilePayload($parameter);

            expect($payload)->toBe([
                'mode' => 'attribute',
                'attribute' => FakeQueryParam::class,
                'targetType' => null,
            ])->and($resolver->resolveParameterPlan($parameter, $payload, $provided))->toBe([0, 'planned']);
        });

        it('compiles mapper target type metadata into the request payload', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $parameter = typedParam('mapperToDto', 0, RequestTargets::class);
            $provided = requestProvided(requestWithQuery(['q' => 'dto']));

            $payload = $resolver->compilePayload($parameter);
            $result = $resolver->resolveParameterPlan($parameter, $payload, $provided);

            expect($payload)->toBe([
                'mode' => 'attribute',
                'attribute' => FakeQueryMap::class,
                'targetType' => RequestDtoTarget::class,
            ])->and($result[1])->toBeInstanceOf(RequestDtoTarget::class)
                ->and($result[1]->q)->toBe('dto');
        });

        it('compiles and resolves a UriInterface payload', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $parameter = typedParam('singleValue', 2, RequestTargets::class);
            $request = requestWithQuery(['x' => 'y']);

            $payload = $resolver->compilePayload($parameter);
            $result = $resolver->resolveParameterPlan($parameter, $payload, requestProvided($request));

            expect($payload)->toBe(['mode' => 'uri'])
                ->and($result[0])->toBe(2)
                ->and($result[1])->toBeInstanceOf(UriInterface::class);
        });

        it('falls back to the runtime request resolver when the payload is invalid', function () {
            $resolver = new RequestResolver(hydratingFactory(), noopCasterProvider());
            $parameter = typedParam('singleValue', 0, RequestTargets::class);
            $provided = requestProvided(requestWithQuery(['q' => 'fallback']));

            expect($resolver->resolveParameterPlan($parameter, ['invalid' => true], $provided))
                ->toBe([0, 'fallback']);
        });
    });
});
