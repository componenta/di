<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;

final readonly class FirstRequestDtoType {}
final readonly class SecondRequestDtoType {}

function acceptsAmbiguousRequestDto(
    #[MapRequestPayload] FirstRequestDtoType|SecondRequestDtoType $dto,
): void {}

it('rejects request mapping to more than one possible DTO class', function (): void {
    $factory = new class () implements FactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            throw new LogicException('Factory must not run for an ambiguous DTO type.');
        }
    };
    $parameter = (new ReflectionFunction('acceptsAmbiguousRequestDto'))->getParameters()[0];
    $resolver = new RequestResolver($factory, new NullCasterProvider());
    $context = new ParameterResolutionContext(RequestParameter::with(
        [],
        (new ServerRequest('POST', '/'))->withParsedBody([]),
    ));

    expect(fn() => $resolver->resolveParameter(new ParameterTarget($parameter), $context))
        ->toThrow(
            ResolutionException::class,
            'request DTO mapping requires exactly one class type',
        );
});
