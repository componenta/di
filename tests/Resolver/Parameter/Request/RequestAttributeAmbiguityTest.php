<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest;

it('rejects multiple request extraction attributes instead of using declaration order', function (): void {
    $container = (new ContainerBuilder())->build();
    $resolver = new RequestResolver($container, new NullCasterProvider());
    $callable = static function (
        #[QueryParam('value')]
        #[PayloadParam('value')]
        string $value,
    ): string {
        return $value;
    };
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];
    $request = new FakeServerRequest(
        queryParams: ['value' => 'query'],
        parsedBody: ['value' => 'payload'],
    );

    expect(fn() => $resolver->resolveParameter(
        new ParameterTarget($parameter),
        new ParameterResolutionContext(RequestParameter::with([], $request)),
    ))->toThrow(
        ResolutionException::class,
        'multiple request extraction attributes are ambiguous',
    );
});
