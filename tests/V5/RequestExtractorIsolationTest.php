<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class AuditUntouchedRequestExtractor implements ExtractorInterface
{
    /** @var list<string> */
    public static array $seenAttributes = [];

    public function extract(ServerRequestInterface $request): mixed
    {
        self::$seenAttributes = array_keys($request->getAttributes());
        return 'custom-ok';
    }
}

final readonly class AuditUnusedRequestFactory implements FactoryInterface
{
    public function make(string $entry, array $params = []): object
    {
        throw new \LogicException('DTO construction is not expected in this test.');
    }
}

final readonly class AuditNullCasterProvider implements CasterProviderInterface
{
    public function provide(string $name): ?CasterInterface
    {
        return null;
    }
}

test('custom request extractors receive the original PSR request without DI metadata', function (): void {
    AuditUntouchedRequestExtractor::$seenAttributes = [];
    $handler = new RequestAttributeHandler(
        new AuditUnusedRequestFactory(),
        new AuditNullCasterProvider(),
    );
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AuditUntouchedRequestExtractor::class,
            $handler,
            capabilities: [ValueProvider::class],
        ))
        ->build();
    $request = (new ServerRequest('GET', '/'))->withAttribute('public', 'visible');

    $result = $container->call(
        static fn(#[AuditUntouchedRequestExtractor] string $value): string => $value,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe('custom-ok')
        ->and(AuditUntouchedRequestExtractor::$seenAttributes)->toBe(['public']);
});

test('built in request extractors still use the declaring parameter name as fallback', function (): void {
    $container = (new ContainerBuilder())->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['term' => 'needle']);

    $result = $container->call(
        static fn(#[QueryParam] string $term): string => $term,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe('needle');
});
