<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final readonly class PriorityResolverA implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

final readonly class PriorityResolverB implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

final class PreconfiguredPriorityBuilder extends ContainerBuilder
{
    public function __construct()
    {
        parent::__construct();
        $this->addParameterResolver(new PriorityResolverA(), 5000);
    }
}

test('builder rejects duplicate user parameter resolver priorities immediately', function (): void {
    $builder = (new ContainerBuilder())->addParameterResolver(new PriorityResolverA(), 5000);

    expect(fn() => $builder->addParameterResolver(new PriorityResolverB(), 5000))
        ->toThrow(InvalidConfigurationException::class, 'priority 5000 is already registered');
});

test('configuration replaces a preconfigured resolver at the same priority', function (): void {
    $replacement = new PriorityResolverB();
    $builder = PreconfiguredPriorityBuilder::configureWithDependencies(
        new Config([]),
        [ConfigKey::PARAMETER_RESOLVERS => [5000 => $replacement]],
    );

    $resolvers = $builder->toArray()[ConfigKey::DEPENDENCIES][ConfigKey::PARAMETER_RESOLVERS];

    expect($resolvers[5000])->toBe($replacement);
});
