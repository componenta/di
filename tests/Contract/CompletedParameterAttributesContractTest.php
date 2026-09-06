<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompletedParameters;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ObserveInput {}

final class InputObserver implements ParameterAttributeHandlerInterface
{
    public int $calls = 0;
    public function resolveParameter(object $attribute, ParameterTarget $target, ParameterResolutionContext $context, AttributePlan $plan, ParameterAttributeValue $value): ParameterAttributeValue
    {
        ++$this->calls;
        return $value;
    }
}

final class Executions
{
    public static int $bodies = 0;
}

it('rejects input policy changes on a parameter already resolved before a later dependency loads its source', function (bool $preloaded, bool $observed): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'RequestSource_' . $suffix;
    $function = 'readRequest_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    $prefix = $observed ? '#[ObserveInput]' : '';
    eval(sprintf('namespace %s; function %s(%s #[%s] \\Psr\\Http\\Message\\ServerRequestInterface $request, #[\\Componenta\\DI\\Attribute\\EntryId("loader")] object $loader): string { ++Executions::$bodies; return $request->getUri()->getPath(); }', __NAMESPACE__, $function, $prefix, $alias));
    if ($preloaded) {
        class_alias(CurrentRequest::class, $fullAlias);
    }
    $observer = new InputObserver();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ObserveInput::class, $observer, [ValueTransformer::class]))
        ->addFactory('loader', static function () use ($fullAlias): object {
            if (!class_exists($fullAlias, false)) {
                class_alias(CurrentRequest::class, $fullAlias);
            }
            return new \stdClass();
        })
        ->build();
    Executions::$bodies = 0;
    $error = null;
    $result = null;
    $params = ['request' => new ServerRequest('GET', '/caller'), ServerRequestInterface::class => new ServerRequest('GET', '/actual')];
    try {
        $result = $container->call(__NAMESPACE__ . '\\' . $function, $params);
    } catch (AttributeCompositionException $exception) {
        $error = $exception::class;
    }
    $first = [$error, $result, Executions::$bodies, $observer->calls];
    $next = $container->call(__NAMESPACE__ . '\\' . $function, $params);
    expect($next)->toBe('/actual')
        ->and($first)->toBe($preloaded ? [null, '/actual', 1, $observed ? 1 : 0] : [AttributeCompositionException::class, null, 0, $observed ? 1 : 0])
        ->and($observer->calls)->toBe($observed ? 2 : 0);
})->with([
    'unknown source, late' => [false, false],
    'unknown source, preloaded control' => [true, false],
    'known handler, late' => [false, true],
    'known handler, preloaded control' => [true, true],
]);


#[Attribute(Attribute::TARGET_PARAMETER)]
final class UnrelatedMarker {}

it('allows a late unrelated attribute on an already resolved parameter', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'UnrelatedAlias_' . $suffix;
    $function = 'unrelatedInput_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    eval(sprintf('namespace %s; function %s(#[%s] string $value, #[\Componenta\DI\Attribute\EntryId("loader")] object $loader): string { return $value; }', __NAMESPACE__, $function, $alias));
    $container = (new ContainerBuilder())->addFactory('loader', static function () use ($fullAlias): object {
        class_alias(UnrelatedMarker::class, $fullAlias);
        return new \stdClass();
    })->build();

    expect($container->call(__NAMESPACE__ . '\\' . $function, ['value' => 'caller']))->toBe('caller');
});
