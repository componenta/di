<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestMappingContractDto
{
    /** @param array<string, mixed>|null $orderBy */
    public function __construct(
        public int $value,
        public string $mode,
        public ?array $orderBy,
    ) {}
}

test('MapRequest maps casts defaults sorts and excludes fields', function (): void {
    $request = (new ServerRequest('GET', '/items'))
        ->withQueryParams([
            'raw' => '42',
            'sort' => 'newest',
            'order' => 'desc',
            'drop' => 'remove-me',
        ]);
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();

    $dto = $container->call(
        static fn(
            #[MapRequest(
                sources: [RequestDataSource::Query],
                map: ['raw' => 'value'],
                exclude: ['drop'],
                defaults: ['mode' => 'fallback'],
                cast: ['value' => 'int'],
                sortMap: ['newest' => ['createdAt' => 'DESC']],
            )]
            RequestMappingContractDto $dto,
        ): RequestMappingContractDto => $dto,
        [ServerRequestInterface::class => $request],
    );

    expect($dto->value)->toBe(42)
        ->and($dto->mode)->toBe('fallback')
        ->and($dto->orderBy)->toBe(['createdAt' => 'DESC']);
});

test('MapRequest can merge selected request attributes without exposing all attributes', function (): void {
    $request = (new ServerRequest('GET', '/items'))
        ->withQueryParams(['query' => 'term'])
        ->withAttribute('route_id', 17)
        ->withAttribute('private', 'hidden');
    $container = (new ContainerBuilder())->build();

    $data = $container->call(
        static fn(
            #[MapRequest(
                sources: [RequestDataSource::Query],
                attributes: ['route_id'],
            )]
            array $data,
        ): array => $data,
        [ServerRequestInterface::class => $request],
    );

    expect($data)->toBe(['route_id' => 17, 'query' => 'term']);
});

test('MapRequest validates raw transport data before transformations', function (): void {
    $validated = [];
    $validator = new class ($validated) implements ValidatorInterface {
        /** @param list<array<string, mixed>> $validated */
        public function __construct(private array &$validated) {}

        public function validate(
            iterable $data,
            ?ContextInterface $context = null,
        ): true|ErrorMessageCollectorInterface {
            $this->validated[] = is_array($data) ? $data : iterator_to_array($data);
            return true;
        }
    };
    $validation = new class ($validator) implements ValidationProviderInterface {
        public function __construct(private readonly ValidatorInterface $validator) {}

        public function provide(string $entryId): ?ValidatorInterface
        {
            return $entryId === RequestMappingContractDto::class ? $this->validator : null;
        }
    };
    $request = (new ServerRequest('GET', '/items'))->withQueryParams(['raw' => '7']);
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->addService(ValidationProviderInterface::class, $validation)
        ->build();

    $container->call(
        static fn(
            #[MapRequest(
                sources: [RequestDataSource::Query],
                map: ['raw' => 'value'],
                defaults: ['mode' => 'fallback', 'orderBy' => null],
                cast: ['value' => 'int'],
            )]
            RequestMappingContractDto $dto,
        ): RequestMappingContractDto => $dto,
        [ServerRequestInterface::class => $request],
    );

    expect($validated)->toBe([['raw' => '7']]);
});
