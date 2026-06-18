<?php

declare(strict_types=1);

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;
use Componenta\DI\Tests\Fixture\ConfigurableQueryMapper;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;
use Componenta\DI\Tests\Fixture\FakeUploadedFile as UploadedFile;
use Psr\Http\Message\ServerRequestInterface;

function requestWithAttributes(array $attrs): ServerRequest
{
    $request = new ServerRequest('GET', '/');
    foreach ($attrs as $key => $value) {
        $request = $request->withAttribute($key, $value);
    }
    return $request;
}

function resolveRequestMapper(RequestDataExtractorInterface&MapperInterface $mapper, ServerRequestInterface $request): array
{
    return $mapper->transform($mapper->extract($request));
}

describe('Attribute\\RequestMapper', function () {
    describe('extract() base behaviour (via MapQueryString subclass)', function () {
        it('extracts listed request attributes by name', function () {
            $mapper = new ConfigurableQueryMapper(attributes: ['id', 'slug']);
            $request = requestWithAttributes(['id' => 42, 'slug' => 'abc', 'ignored' => 'x']);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['id' => 42, 'slug' => 'abc']);
        });

        it('omits a listed attribute when missing', function () {
            $mapper = new ConfigurableQueryMapper(attributes: ['id']);
            $request = new ServerRequest('GET', '/');

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe([]);
        });

        it('wildcard * extracts every request attribute', function () {
            $mapper = new ConfigurableQueryMapper(
                attributes: [RequestMapperPipeline::WILDCARD],
            );
            $request = requestWithAttributes(['a' => 1, 'b' => 2]);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['a' => 1, 'b' => 2]);
        });

        it('extracts listed uploaded files by key', function () {
            $file = new UploadedFile();
            $mapper = new ConfigurableQueryMapper(files: ['avatar']);
            $request = (new ServerRequest('POST', '/'))
                ->withUploadedFiles(['avatar' => $file, 'ignored' => $file]);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['avatar' => $file]);
        });

        it('omits a listed file key when the file is missing', function () {
            $mapper = new ConfigurableQueryMapper(files: ['avatar']);
            $request = new ServerRequest('POST', '/');

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe([]);
        });

        it('wildcard * extracts every uploaded file', function () {
            $file = new UploadedFile();
            $mapper = new ConfigurableQueryMapper(
                files: [RequestMapperPipeline::WILDCARD],
            );
            $request = (new ServerRequest('POST', '/'))
                ->withUploadedFiles(['a' => $file, 'b' => $file]);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['a' => $file, 'b' => $file]);
        });
    });

    describe('transform() - full pipeline integration', function () {
        it('applies field mapping, defaults and exclude in declaration order', function () {
            $mapper = new ConfigurableQueryMapper(
                map: ['q' => 'query'],
                defaults: ['page' => 1],
                exclude: ['debug'],
            );
            $request = (new ServerRequest('GET', '/?q=hello&debug=1'))
                ->withQueryParams(['q' => 'hello', 'debug' => '1']);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['query' => 'hello', 'page' => 1]);
        });

        it('applies cast via the configured CasterProviderInterface', function () {
            $caster = new class () implements CasterInterface {
                public string $name { get => 'int'; }

                public function cast(mixed $value): mixed { return (int) $value; }
            };
            $provider = new class ($caster) implements CasterProviderInterface {
                public function __construct(private CasterInterface $caster) {}

                public function provide(string $name): ?CasterInterface
                {
                    return $name === 'int' ? $this->caster : null;
                }
            };

            $mapper = new ConfigurableQueryMapper(cast: ['limit' => 'int']);
            $mapper->provider = $provider;

            $request = (new ServerRequest('GET', '/?limit=25'))
                ->withQueryParams(['limit' => '25']);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['limit' => 25]);
        });

        it('applies sortMap replacing raw sort/order with orderBy', function () {
            $mapper = new ConfigurableQueryMapper(
                sortMap: ['newest' => ['createdAt' => 'desc']],
            );
            $request = (new ServerRequest('GET', '/'))
                ->withQueryParams(['sort' => 'newest', 'order' => 'desc']);

            $result = resolveRequestMapper($mapper, $request);

            expect($result)->toBe(['orderBy' => ['createdAt' => 'desc']]);
        });
    });

    describe('map configuration merge', function () {
        it('merges class-level map defaults with constructor-supplied map', function () {
            $extendedMapper = new \Componenta\DI\Tests\Fixture\ClassDefaultMapMapper(['runtime' => 'runtime_field']);

            $request = (new ServerRequest('GET', '/'))
                ->withQueryParams(['class_default' => 'a', 'runtime' => 'b']);

            $result = resolveRequestMapper($extendedMapper, $request);

            expect($result)->toBe([
                'class_default_field' => 'a',
                'runtime_field' => 'b',
            ]);
        });
    });

    describe('provider property hook', function () {
        it('lazy-initialises to NullCasterProvider on first read when unset', function () {
            $mapper = new MapQueryString();

            $provider = $mapper->provider;

            expect($provider)->toBeInstanceOf(CasterProviderInterface::class);
        });

        it('stores an assigned provider', function () {
            $custom = new class () implements CasterProviderInterface {
                public function provide(string $name): ?CasterInterface { return null; }
            };
            $mapper = new MapQueryString();

            $mapper->provider = $custom;

            expect($mapper->provider)->toBe($custom);
        });
    });
});
