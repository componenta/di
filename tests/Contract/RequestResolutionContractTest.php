<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Componenta\DI\Tests\Fixture\FakeUploadedFile;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

final readonly class RequestContractDto
{
    public function __construct(public string $value) {}
}

function requestContractContainer(?ValidationProviderInterface $validation = null): Container
{
    $caster = new class () implements CasterInterface {
        public string $name {
            get => 'int';
        }

        public function cast(mixed $value): mixed
        {
            return (int) $value;
        }
    };
    $casters = new class ($caster) implements CasterProviderInterface {
        public function __construct(private readonly CasterInterface $caster) {}

        public function provide(string $name): ?CasterInterface
        {
            return $name === $this->caster->name ? $this->caster : null;
        }
    };
    $builder = ContainerBuilder::configure(new Config((new ConfigProvider())()))
        ->addService(CasterProviderInterface::class, $casters);

    if ($validation !== null) {
        $builder->addService(ValidationProviderInterface::class, $validation);
    }

    return $builder->build();
}

it('resolves every scalar PSR-7 attribute and UriInterface through Container::call()', function (): void {
    $file = new FakeUploadedFile();
    $request = (new FakeServerRequest(
        method: 'POST',
        uri: '/orders/17?query=term',
        queryParams: ['query' => 'term', 'limit' => '12'],
        cookieParams: ['session' => 'cookie-value'],
        serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        uploadedFiles: ['avatar' => $file],
        attributes: ['route_id' => 17],
        parsedBody: ['payload' => 'body-value'],
    ))->withHeader('X-Token', 'header-value');
    $container = requestContractContainer();

    $resolved = $container->call(static fn(
        #[QueryParam('query')] string $query,
        #[PayloadParam('payload')] string $payload,
        #[Header('X-Token')] string $header,
        #[Cookie('session')] string $cookie,
        #[RequestAttribute('route_id')] int $routeId,
        #[ServerParam('REMOTE_ADDR')] string $remoteAddress,
        #[UploadedFile('avatar')] UploadedFileInterface $avatar,
        #[QueryParam('limit', cast: 'int')] int $limit,
        UriInterface $uri,
    ): array => [
        $query,
        $payload,
        $header,
        $cookie,
        $routeId,
        $remoteAddress,
        $avatar,
        $limit,
        (string) $uri,
    ], [ServerRequestInterface::class => $request]);

    expect($resolved)->toBe([
        'term',
        'body-value',
        'header-value',
        'cookie-value',
        17,
        '127.0.0.1',
        $file,
        12,
        '/orders/17?query=term',
    ]);
});

it('uses the callable parameter name when a scalar request attribute omits its name', function (): void {
    $request = new FakeServerRequest(queryParams: ['search' => 'needle']);

    expect(requestContractContainer()->call(
        static fn(#[QueryParam] string $search): string => $search,
        [ServerRequestInterface::class => $request],
    ))->toBe('needle');
});

it('requires a PSR-7 request for request attributes and UriInterface resolution', function (): void {
    $container = requestContractContainer();

    expect(fn() => $container->call(
        static fn(#[QueryParam('query')] string $query): string => $query,
    ))->toThrow(ResolutionException::class, 'PSR-7 request is required')
        ->and(fn() => $container->call(
            static fn(UriInterface $uri): UriInterface => $uri,
        ))->toThrow(ResolutionException::class, 'PSR-7 request is required');
});

it('validates raw transport data before mapper transformation and DTO construction', function (): void {
    $validated = [];
    $validator = new class ($validated) implements ValidatorInterface {
        public function __construct(private array &$validated) {}

        public function validate(
            iterable $data,
            ?\Componenta\Validation\ContextInterface $context = null,
        ): true|ErrorMessageCollectorInterface {
            $this->validated[] = is_array($data) ? $data : iterator_to_array($data);

            return true;
        }
    };
    $validation = new class ($validator) implements ValidationProviderInterface {
        public function __construct(private readonly ValidatorInterface $validator) {}

        public function provide(string $entryId): ?ValidatorInterface
        {
            return $entryId === RequestContractDto::class ? $this->validator : null;
        }
    };
    $request = new FakeServerRequest(queryParams: ['raw' => '7']);
    $container = requestContractContainer($validation);

    $dto = $container->call(
        static fn(
            #[MapQueryString(map: ['raw' => 'value'])]
            RequestContractDto $dto,
        ): RequestContractDto => $dto,
        [ServerRequestInterface::class => $request],
    );

    expect($validated)->toBe([['raw' => '7']])
        ->and($dto)->toBeInstanceOf(RequestContractDto::class)
        ->and($dto->value)->toBe('7');
});
