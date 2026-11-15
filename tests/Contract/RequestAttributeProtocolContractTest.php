<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestAttributeProtocol;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CollectionExtractor implements ExtractorInterface, RequestDataExtractorInterface
{
    public function extract(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TransformableExtractor implements ExtractorInterface, MapperInterface
{
    /** @return array<string|int,mixed> */
    public function extract(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    public function transform(array $data): array
    {
        throw new \LogicException('The mapping protocol was not selected.');
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ReadonlyMapper implements RequestDataExtractorInterface, MapperInterface
{
    public function extract(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    public function transform(array $data): array
    {
        return ['mapped' => $data];
    }
}

test('request attributes use simple extraction unless the complete mapping protocol is implemented', function (
    Closure $target,
): void {
    $factory = (new ContainerBuilder())->build();
    $handler = new RequestAttributeHandler($factory, new TestCasterProvider());
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            CollectionExtractor::class,
            $handler,
            [ValueProvider::class],
        ))
        ->addAttributeDefinition(new AttributeDefinition(
            TransformableExtractor::class,
            $handler,
            [ValueProvider::class],
        ))
        ->build();

    foreach (['first', 'next'] as $value) {
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => $value]);

        expect($container->call($target, [ServerRequestInterface::class => $request]))
            ->toBe(['value' => $value]);
    }
})->with([
    'extractor with collection extraction but no mapper' => [
        static fn(#[CollectionExtractor] array $input): array => $input,
    ],
    'extractor with a mapper but no collection extraction protocol' => [
        static fn(#[TransformableExtractor] array $input): array => $input,
    ],
]);

test('a readonly custom request mapper needs no optional caster provider property', function (): void {
    $factory = (new ContainerBuilder())->build();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ReadonlyMapper::class,
            new RequestAttributeHandler($factory, new TestCasterProvider()),
            [ValueProvider::class],
        ))
        ->build();
    $target = static fn(#[ReadonlyMapper] array $input): array => $input;

    foreach (['first', 'next'] as $value) {
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => $value]);

        expect($container->call($target, [ServerRequestInterface::class => $request]))
            ->toBe(['mapped' => ['value' => $value]]);
    }
});
