<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestCacheParityContract;

use Attribute;
use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Config as ConfigValue;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile as RequestUpload;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class CacheControl {}

final class CacheControlRule implements AttributeCompositionRuleInterface
{
    public private(set) int $validations = 0;

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        ++$this->validations;
    }
}

function builder(?CacheControlRule $rule = null, ?TrustedValue $trusted = null): ContainerBuilder
{
    return (new ContainerBuilder(new Config(['trusted' => $trusted], new Environment([]))))
        ->addAttributeDefinition(new AttributeDefinition(
            CacheControl::class,
            rules: $rule === null ? [] : [$rule],
        ));
}

final class Consumer
{
    /** @return list<mixed> */
    public function extract(
        #[CacheControl, CurrentRequest] ServerRequestInterface $request,
        #[CacheControl, CurrentUri] UriInterface $uri,
        #[CacheControl, QueryParam('query')] mixed $query,
        #[CacheControl, PayloadParam(new ConfigPath('nested.value'))] mixed $payload,
        #[CacheControl, Header('X-Token')] string $header,
        #[CacheControl, Cookie('cookie')] mixed $cookie,
        #[CacheControl, RequestAttribute('attribute')] mixed $attribute,
        #[CacheControl, ServerParam('server')] mixed $server,
        #[CacheControl, UploadedFile('attachment')] ?UploadedFileInterface $upload,
        #[CacheControl, QueryParam] mixed $fallback,
        #[CacheControl, QueryParam('absent', default: 'default')] mixed $missing,
    ): array {
        return [$request, $uri, $query, $payload, $header, $cookie, $attribute, $server, $upload, $fallback, $missing];
    }

    /**
     * @param array<array-key,mixed> $query
     * @param array<array-key,mixed> $payload
     * @param array<array-key,mixed> $headers
     * @param array<array-key,mixed> $cookies
     * @param array<array-key,mixed> $attributes
     * @param array<array-key,mixed> $server
     * @param array<array-key,mixed> $files
     * @param array<array-key,mixed> $combined
     * @return list<array<array-key,mixed>>
     */
    public function map(
        #[CacheControl, MapQueryString] array $query,
        #[CacheControl, MapRequestPayload] array $payload,
        #[CacheControl, MapHeaders] array $headers,
        #[CacheControl, MapCookies] array $cookies,
        #[CacheControl, MapRequestAttributes] array $attributes,
        #[CacheControl, MapServerParams] array $server,
        #[CacheControl, MapUploadedFiles] array $files,
        #[CacheControl, MapRequest(sources: [RequestDataSource::Query, RequestDataSource::Cookies])] array $combined,
    ): array {
        return [$query, $payload, $headers, $cookies, $attributes, $server, $files, $combined];
    }
}

final class StampProvider implements CasterProviderInterface, CasterInterface
{
    /** @var list<mixed> */
    public array $seen = [];
    public string $name { get => 'stamp'; }

    public function __construct(private readonly string $prefix) {}

    public function provide(string $name): ?CasterInterface
    {
        return $name === 'stamp' ? $this : null;
    }

    public function cast(mixed $value): mixed
    {
        $this->seen[] = $value;
        if (!is_string($value)) {
            throw new \LogicException('Expected a raw request string.');
        }
        return $this->prefix . $value;
    }
}

final class ValidationProvider implements ValidationProviderInterface, ValidatorInterface
{
    /** @var list<array<array-key,mixed>> */
    public array $seen = [];
    public bool $reject = false;

    public function provide(string $entryId): ?ValidatorInterface
    {
        return $entryId === MappedValue::class ? $this : null;
    }

    /** @param iterable<array-key,mixed> $data */
    public function validate(iterable $data, ?ContextInterface $context = null): true|ErrorMessageCollectorInterface
    {
        $this->seen[] = is_array($data) ? $data : iterator_to_array($data);
        if ($this->reject) {
            throw new \RuntimeException('Rejected current request.');
        }
        return true;
    }
}

final readonly class MappedValue
{
    public function __construct(public string $value) {}
}

final class MappingConsumer
{
    public int $calls = 0;

    public function cast(#[CacheControl, MapRequest(sources: [RequestDataSource::Query], cast: ['value' => 'stamp'])] MappedValue $dto): string
    {
        ++$this->calls;
        return $dto->value;
    }

    public function trusted(#[CacheControl, MapQueryString] TrustedDto $dto): TrustedDto
    {
        ++$this->calls;
        return $dto;
    }
}

final readonly class TrustedValue
{
    public function __construct(public string $label) {}
}

final readonly class TrustedDto
{
    public function __construct(#[CacheControl, ConfigValue('trusted')] public TrustedValue $trusted, public mixed $value) {}
}

/** @return array{class-string<Throwable>,string,class-string<Throwable>|null} */
function failure(Closure $operation): array
{
    try {
        $operation();
    } catch (Throwable $error) {
        return [$error::class, $error->getMessage(), $error->getPrevious() === null ? null : $error->getPrevious()::class];
    }
    throw new \LogicException('Expected the invalid request to fail.');
}

test('warmed request extractor plans preserve every current source and authoritative request identity', function (): void {
    $rule = new CacheControlRule();
    $containers = [builder()->build(), builder($rule)->build()];
    $spoof = new ServerRequest('GET', '/spoof');
    $consumer = new Consumer();
    foreach ([null, false, 0, 'changed'] as $index => $value) {
        $validations = $rule->validations;
        $upload = new RequestUpload(Stream::create('file-' . $index), 6, UPLOAD_ERR_OK);
        $request = (new ServerRequest('POST', '/request-' . $index, ['X-Token' => ['first', (string) $index]], '', '1.1', ['server' => $value]))
            ->withQueryParams(['query' => $value, 'fallback' => $value])
            ->withParsedBody((object) ['nested' => (object) ['value' => $value]])
            ->withCookieParams(['cookie' => $value])
            ->withAttribute('attribute', $value)
            ->withUploadedFiles(['attachment' => $upload]);
        $provided = [ServerRequestInterface::class => $request, 'request' => $spoof, 'uri' => $spoof->getUri(), UriInterface::class => $spoof->getUri()];
        $expected = [$request, $request->getUri(), $value, $value, 'first, ' . $index, $value, $value, $value, $upload, $value, 'default'];
        foreach ($containers as $container) {
            expect($container->call([$consumer, 'extract'], $provided))->toBe($expected);
            $explicit = $expected;
            $explicit[2] = 'explicit';
            expect($container->call([$consumer, 'extract'], ['query' => 'explicit', ...$provided]))->toBe($explicit);
        }
        expect($rule->validations)->toBeGreaterThan($validations);
    }
    $errors = [];
    foreach ($containers as $container) {
        $errors[] = failure(fn() => $container->call([$consumer, 'extract']));
    }
    expect($errors[0])->toBe($errors[1]);
});

test('all warmed request mapper plans preserve source keys nulls objects and uploads', function (): void {
    $rule = new CacheControlRule();
    $containers = [builder()->build(), builder($rule)->build()];
    $consumer = new Consumer();
    foreach ([null, false, 'changed'] as $index => $value) {
        $validations = $rule->validations;
        $upload = new RequestUpload(Stream::create('file-' . $index), 6, UPLOAD_ERR_OK);
        $leaf = (object) ['value' => $value];
        $request = (new ServerRequest('POST', '/map-' . $index, ['X-Token' => ['a', 'b']], '', '1.1', ['server' => $value]))
            ->withQueryParams(['query' => $value])
            ->withParsedBody((object) ['payload' => $leaf])
            ->withCookieParams(['cookie' => $value])
            ->withAttribute('attribute', $value)
            ->withUploadedFiles(['attachment' => $upload]);
        $expected = [
            ['query' => $value], ['payload' => $leaf], ['X-Token' => 'a, b'], ['cookie' => $value],
            ['attribute' => $value], ['server' => $value], ['attachment' => $upload], ['query' => $value, 'cookie' => $value],
        ];
        foreach ($containers as $container) {
            expect($container->call([$consumer, 'map'], [ServerRequestInterface::class => $request]))->toBe($expected);
        }
        expect($rule->validations)->toBeGreaterThan($validations);
    }
});

test('warmed mapping plans read current provider bindings and validate raw data before casting', function (): void {
    $outcomes = [];
    foreach ([null, new CacheControlRule()] as $rule) {
        $first = new StampProvider('first:');
        $second = new StampProvider('second:');
        $validation = new ValidationProvider();
        $replacement = new ValidationProvider();
        $container = builder($rule)
            ->addServices(['caster.first' => $first, 'caster.second' => $second, ValidationProviderInterface::class => $validation])
            ->addAlias(CasterProviderInterface::class, 'caster.first')
            ->build();
        $consumer = new MappingConsumer();
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'raw']);
        $provided = [ServerRequestInterface::class => $request];
        expect($container->call([$consumer, 'cast'], $provided))->toBe('first:raw');
        $validations = $rule->validations ?? 0;
        $container->alias(CasterProviderInterface::class, 'caster.second');
        $container->set(ValidationProviderInterface::class, $replacement);
        expect($container->call([$consumer, 'cast'], $provided))->toBe('second:raw');
        if ($rule !== null) {
            expect($rule->validations)->toBeGreaterThan($validations);
        }
        $replacement->reject = true;
        $error = failure(fn() => $container->call([$consumer, 'cast'], $provided));
        expect($consumer->calls)->toBe(2)
            ->and($first->seen)->toBe(['raw'])
            ->and($second->seen)->toBe(['raw'])
            ->and($validation->seen)->toBe([['value' => 'raw']])
            ->and($replacement->seen)->toBe([['value' => 'raw'], ['value' => 'raw']]);
        $replacement->reject = false;
        expect($container->call([$consumer, 'cast'], [ServerRequestInterface::class => $request->withQueryParams(['value' => 'next'])]))->toBe('second:next');
        $outcomes[] = [$error, $consumer->calls, $first->seen, $second->seen, $validation->seen, $replacement->seen];
    }
    expect($outcomes[0])->toBe($outcomes[1]);
});

test('static mapped DTO source metadata does not retain another container or permit shadowing after warmup', function (): void {
    $errors = [];
    foreach ([null, new CacheControlRule(), null] as $index => $rule) {
        $trusted = new TrustedValue('container-' . $index);
        $container = builder($rule, $trusted)->build();
        $consumer = new MappingConsumer();
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => null]);
        foreach ([null, false] as $value) {
            $validations = $rule->validations ?? 0;
            $dto = $container->call([$consumer, 'trusted'], [ServerRequestInterface::class => $request->withQueryParams(['value' => $value])]);
            expect($dto)->toBeInstanceOf(TrustedDto::class);
            if (!$dto instanceof TrustedDto) {
                throw new \LogicException('Expected a mapped DTO.');
            }
            expect($dto->trusted)->toBe($trusted)->and($dto->value)->toBe($value);
            if ($rule !== null) {
                expect($rule->validations)->toBeGreaterThan($validations);
            }
        }
        foreach (['trusted', TrustedValue::class] as $key) {
            $error = failure(fn() => $container->call([$consumer, 'trusted'], [
                ServerRequestInterface::class => $request->withQueryParams(['value' => 'valid', $key => new TrustedValue('untrusted')]),
            ]));
            expect($error[0])->toBe(RequestParameterSourceConflictException::class)
                ->and($consumer->calls)->toBe(2);
            $errors[$key][] = $error;
        }
    }
    foreach ($errors as $group) {
        expect($group[0])->toBe($group[1])->and($group[1])->toBe($group[2]);
    }
});
