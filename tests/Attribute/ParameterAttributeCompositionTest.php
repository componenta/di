<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\ParameterAttributeComposition;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\fixtureInstance;

final class QueryThenCastDto
{
    public function __construct(
        #[QueryParam('count'), Cast('int')]
        public int $count,
    ) {}
}

final class CastDeclaredBeforeQueryDto
{
    public function __construct(
        #[Cast('int'), QueryParam('count')]
        public int $count,
    ) {}
}

final class ConflictingRequestSourcesDto
{
    public function __construct(
        #[QueryParam('value'), Header('X-Value')]
        public string $value,
    ) {}
}

final class ExplicitCastCompositionDto
{
    public function __construct(#[Cast('int')] public int $value) {}
}

final class ConfigThenCastPropertyDto
{
    #[Cast('trim'), ConfigAttribute('raw')]
    public string $value;
}

abstract class RequestSourceFixtures extends TestCase
{
    public static function unreadableRequest(): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $failure = new LogicException('Conflicting request sources must fail before reading the request.');
        $request->method('getQueryParams')->willThrowException($failure);
        $request->method('hasHeader')->willThrowException($failure);
        return $request;
    }
}

function composedParameterContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

test('a request value source composes with Cast on one parameter', function (): void {
    $request = (new ServerRequest('GET', '/?count=41'))
        ->withQueryParams(['count' => '41']);

    $entry = composedParameterContainer()->make(
        QueryThenCastDto::class,
        [ServerRequestInterface::class => $request],
    );
    Assert::assertInstanceOf(QueryThenCastDto::class, $entry);

    expect($entry->count)->toBe(41);
});

test('composition ordering runs value providers before transformers regardless of declaration order', function (): void {
    $request = (new ServerRequest('GET', '/?count=42'))
        ->withQueryParams(['count' => '42']);

    $entry = composedParameterContainer()->make(
        CastDeclaredBeforeQueryDto::class,
        [ServerRequestInterface::class => $request],
    );
    Assert::assertInstanceOf(CastDeclaredBeforeQueryDto::class, $entry);

    expect($entry->count)->toBe(42);
});

test('incompatible parameter value sources fail composition before resolution', function (): void {
    $request = RequestSourceFixtures::unreadableRequest();

    expect(fn() => composedParameterContainer()->make(
        ConflictingRequestSourcesDto::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(
        AttributeCompositionException::class,
        'accepts at most 1 attribute(s) with capability ' . ValueProvider::class
            . '; found #[' . QueryParam::class . '], #[' . Header::class . '].',
    );
});

test('attribute composition transforms explicit caller input', function (): void {
    $entry = composedParameterContainer()->make(
        ExplicitCastCompositionDto::class,
        ['value' => '43'],
    );
    Assert::assertInstanceOf(ExplicitCastCompositionDto::class, $entry);

    expect($entry->value)->toBe(43);
});

test('property value providers compose with transformers in semantic order', function (): void {
    $container = ContainerBuilder::configure(new Config(
        ['raw' => '  composed  '],
        new Environment([]),
    ))
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();

    expect(fixtureInstance($container->make(ConfigThenCastPropertyDto::class), ConfigThenCastPropertyDto::class)->value)->toBe('composed');
});

test('composed parameter attributes execute identically for every container build', function (): void {
    $containers = [
        composedParameterContainer(),
        composedParameterContainer(),
    ];
    $request = (new ServerRequest('GET', '/?count=44'))
        ->withQueryParams(['count' => '44']);
    $params = [ServerRequestInterface::class => $request];

    foreach ($containers as $container) {
        expect(fixtureInstance($container->make(QueryThenCastDto::class, $params), QueryThenCastDto::class)->count)->toBe(44);
    }
});
