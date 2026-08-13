<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest;

it('normalizes foreign request extractor failures as parameter resolution errors', function () {
    $callable = static function (#[QueryParam('required')] string $required): void {};
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];
    $resolver = new RequestResolver(
        new class () implements FactoryInterface {
            public function make(string $entry, array $params = []): object
            {
                throw new LogicException('DTO factory must not run for scalar extraction.');
            }
        },
        new NullCasterProvider(),
    );
    $context = new ParameterResolutionContext(RequestParameter::with(
        [],
        new FakeServerRequest('GET', '/'),
    ));

    $error = null;

    try {
        $resolver->resolveParameter(new ParameterTarget($parameter), $context);
    } catch (ResolutionException $e) {
        $error = $e;
    }

    expect($error)->toBeInstanceOf(ResolutionException::class)
        ->and($error?->parameter)->toBe($parameter)
        ->and($error?->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($error?->getPrevious()?->getMessage())
        ->toContain('Required query parameter "required" is missing');
});
