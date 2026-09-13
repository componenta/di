<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MappingBoundaryDto
{
    public function __construct(public string $value) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class NumericBoundaryMapper implements RequestDataExtractorInterface, MapperInterface
{
    public function __construct(public bool $numericInput) {}

    public function extract(ServerRequestInterface $request): array
    {
        return $this->numericInput ? [7 => 'data'] : ['value' => 'data'];
    }

    public function transform(array $data): array
    {
        return $this->numericInput ? ['value' => 'data'] : [7 => 'data'];
    }
}

test('request mapping retains array transport data for untyped mixed and array-union parameters', function (Closure $callback): void {
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'data']);
    $container = (new ContainerBuilder())->build();

    expect($container->call($callback, [ServerRequestInterface::class => $request]))->toBe(['value' => 'data']);
})->with([
    [static fn(#[MapQueryString] $data) => $data],
    [static fn(#[MapQueryString] mixed $data): mixed => $data],
    [static fn(#[MapQueryString] MappingBoundaryDto|array $data): MappingBoundaryDto|array => $data],
]);

test('DTO request mapping rejects numeric keys both before and after custom transformation', function (bool $numericInput): void {
    $factory = (new ContainerBuilder())->build();
    $handler = new RequestAttributeHandler($factory, new TestCasterProvider());
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(NumericBoundaryMapper::class, $handler, [ValueProvider::class]))
        ->build();
    $callback = $numericInput
        ? static fn(#[NumericBoundaryMapper(true)] MappingBoundaryDto $dto): MappingBoundaryDto => $dto
        : static fn(#[NumericBoundaryMapper(false)] MappingBoundaryDto $dto): MappingBoundaryDto => $dto;

    expect(fn() => $container->call($callback, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toThrow(ResolutionException::class, 'HTTP DTO mapping accepts only named string keys');
})->with([true, false]);
