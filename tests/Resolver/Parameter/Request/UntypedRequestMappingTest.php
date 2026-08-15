<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;

function acceptsUntypedMappedPayload(#[MapRequestPayload] $payload): void {}

it('maps an untyped request parameter to an array without invoking the DTO factory', function (): void {
    $factory = new class () implements FactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            throw new LogicException('Factory must not run for an untyped mapped parameter.');
        }
    };
    $parameter = (new ReflectionFunction('acceptsUntypedMappedPayload'))->getParameters()[0];
    $resolver = new RequestResolver($factory, new NullCasterProvider());
    $context = new ParameterResolutionContext(RequestParameter::with(
        [],
        (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'value']),
    ));

    expect($resolver->resolveParameter(new ParameterTarget($parameter), $context))
        ->toBe([0, ['name' => 'value']]);
});
