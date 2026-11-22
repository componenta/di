<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use PHPUnit\Framework\Assert;

test('a built container rejects late parameter resolvers and retains its original behavior', function (): void {
    [$container, $runtime] = \Componenta\DI\Tests\Support\assembledContainer();
    $callable = static fn(string $value = 'original'): string => $value;
    expect($container->call($callable))->toBe('original');

    $resolver = new class () implements ParameterResolverInterface {
        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'value';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            return [$target->position, 'replacement'];
        }
    };
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);

    expect(fn() => $parameters->add($resolver, 2000))->toThrow(InvalidConfigurationException::class, 'sealed')
        ->and($container->call($callable))->toBe('original');
});
