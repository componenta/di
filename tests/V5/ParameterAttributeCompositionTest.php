<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionMethod;

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

    expect($entry->count)->toBe(41);
});

test('composition ordering runs value providers before transformers regardless of declaration order', function (): void {
    $request = (new ServerRequest('GET', '/?count=42'))
        ->withQueryParams(['count' => '42']);

    $entry = composedParameterContainer()->make(
        CastDeclaredBeforeQueryDto::class,
        [ServerRequestInterface::class => $request],
    );

    expect($entry->count)->toBe(42);
});

test('incompatible parameter value sources fail composition before resolution', function (): void {
    $request = (new ServerRequest('GET', '/?value=query'))
        ->withQueryParams(['value' => 'query'])
        ->withHeader('X-Value', 'header');

    expect(fn() => composedParameterContainer()->make(
        ConflictingRequestSourcesDto::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(AttributeCompositionException::class);
});

test('attribute resolution can transform raw explicit input without ArrayResolver knowing attributes', function (): void {
    $entry = composedParameterContainer()->make(
        ExplicitCastCompositionDto::class,
        ['value' => '43'],
    );

    expect($entry->value)->toBe(43);

    $parameter = (new ReflectionMethod(ExplicitCastCompositionDto::class, '__construct'))
        ->getParameters()[0];

    expect((new ArrayResolver())->supports(new ParameterTarget($parameter)))->toBeTrue();
});

test('parameter-only attribute handlers do not inherit object attribute execution', function (): void {
    expect(is_subclass_of(ParameterAttributeHandlerInterface::class, AttributeHandlerInterface::class))
        ->toBeFalse()
        ->and((new ReflectionClass(RequestAttributeHandler::class))->hasMethod('handle'))
        ->toBeFalse();
});
