<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
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

final readonly class OptionalSwapMappingTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(
            sources: [RequestDataSource::Query],
            map: ['left' => 'right', 'right' => 'left', '?missing' => 'ignored'],
        )]
        public array $data,
    ) {}
}

final readonly class MissingSortMappingTarget
{
    /** @param array<string|int,mixed> $data */
    public function __construct(
        #[MapRequest(
            sources: [RequestDataSource::Query],
            sortMap: ['recent' => ['createdAt' => 'DESC']],
        )]
        public array $data,
    ) {}
}

final readonly class MissingRequiredMappingTarget
{
    /** @param array<string|int, mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query], map: ['missing' => 'value'])]
        public array $data,
    ) {}
}

final readonly class EmptyTargetMappingTarget
{
    /** @param array<string|int, mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query], map: ['value' => ''])]
        public array $data,
    ) {}
}

final readonly class DuplicateTargetMappingTarget
{
    /** @param array<string|int, mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query], map: [
            'first' => 'value',
            'second' => 'value',
        ])]
        public array $data,
    ) {}
}

final readonly class ExistingTargetMappingTarget
{
    /** @param array<string|int, mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query], map: ['source' => 'target'])]
        public array $data,
    ) {}
}

final readonly class InvalidSortMappingTarget
{
    /** @param array<string|int, mixed> $data */
    public function __construct(
        #[MapRequest(
            sources: [RequestDataSource::Query],
            sortMap: ['recent' => ['createdAt' => 'DESC']],
        )]
        public array $data,
    ) {}
}

final readonly class UnknownCasterMappingTarget
{
    /** @param array<string|int, mixed> $data */
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query], cast: ['value' => 'missing-caster'])]
        public array $data,
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
    if (!$dto instanceof RequestMappingContractDto) {
        throw new \LogicException('Expected MapRequest to return the mapped DTO.');
    }

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
    $validator = new class () implements ValidatorInterface {
        /** @var list<array<string, mixed>> */
        private array $validated = [];

        /** @param iterable<array-key,mixed> $data */
        public function validate(
            iterable $data,
            ?ContextInterface $context = null,
        ): true|ErrorMessageCollectorInterface {
            $values = is_array($data) ? $data : iterator_to_array($data);
            $record = [];
            foreach ($values as $key => $value) {
                if (!is_string($key)) {
                    throw new \LogicException('Expected mapped request data to use string keys.');
                }
                $record[$key] = $value;
            }

            $this->validated[] = $record;
            return true;
        }

        /** @return list<array<string,mixed>> */
        public function validated(): array
        {
            return $this->validated;
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

    expect($validator->validated())->toBe([['raw' => '7']]);
});

test('MapRequest applies optional renames and atomic key swaps', function (): void {
    $request = (new ServerRequest('GET', '/items'))->withQueryParams([
        'left' => 'L',
        'right' => 'R',
    ]);
    $container = (new ContainerBuilder())->build();

    $data = $container->make(
        OptionalSwapMappingTarget::class,
        [ServerRequestInterface::class => $request],
    )->data;

    expect($data)->toBe(['right' => 'L', 'left' => 'R']);
});

test('MapRequest emits a null order when sort aliases are configured but absent', function (): void {
    $request = (new ServerRequest('GET', '/items'))->withQueryParams(['value' => 'kept']);
    $container = (new ContainerBuilder())->build();

    $data = $container->make(
        MissingSortMappingTarget::class,
        [ServerRequestInterface::class => $request],
    )->data;

    expect($data)->toBe(['value' => 'kept', 'orderBy' => null]);
});

test(
    'MapRequest rejects ambiguous or invalid transformations at the public boundary',
    /**
     * @param array<string, mixed> $query
     */
    function (
        string $target,
        array $query,
        string $message,
    ): void {
        if ($target === '') {
            throw new \LogicException('Expected a non-empty request mapping target.');
        }

        $request = (new ServerRequest('GET', '/items'))->withQueryParams($query);
        $container = (new ContainerBuilder())
            ->addService(CasterProviderInterface::class, new TestCasterProvider())
            ->build();

        expect(fn() => $container->make(
            $target,
            [ServerRequestInterface::class => $request],
        ))->toThrow(ResolutionException::class, $message);
    },
)->with([
    'missing required source' => [
        MissingRequiredMappingTarget::class,
        [],
        'Required key "missing" is missing',
    ],
    'empty target name' => [
        EmptyTargetMappingTarget::class,
        ['value' => 'source'],
        'Mapped target key cannot be empty',
    ],
    'duplicate target owner' => [
        DuplicateTargetMappingTarget::class,
        ['first' => 'one', 'second' => 'two'],
        'is produced by both "first" and "second"',
    ],
    'existing target collision' => [
        ExistingTargetMappingTarget::class,
        ['source' => 'source-value', 'target' => 'target-value'],
        'already exists in input',
    ],
    'invalid sort alias type' => [
        InvalidSortMappingTarget::class,
        ['sort' => ['recent']],
        'Sort alias must be a string or integer',
    ],
    'unknown caster' => [
        UnknownCasterMappingTarget::class,
        ['value' => 'source'],
        'missing-caster',
    ],
]);
