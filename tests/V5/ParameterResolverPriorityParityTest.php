<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final readonly class PriorityResolverA implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target) ? [$target->position, 'priority-a'] : null;
    }
}

final readonly class PriorityResolverB implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target) ? [$target->position, 'priority-b'] : null;
    }
}

final readonly class PriorityResolvedTarget
{
    public function __construct(public string $value) {}
}

test('dependency definitions reject non-integer parameter resolver priorities', function (): void {
    expect(fn() => (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            ConfigKey::PARAMETER_RESOLVERS => [
                'high' => new PriorityResolverA(),
            ],
        ]),
    ))->toThrow(InvalidConfigurationException::class, 'priority must be int');
});

test('higher parameter resolver priorities win in the public resolution pipeline', function (): void {
    $value = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            ConfigKey::PARAMETER_RESOLVERS => [
                4000 => new PriorityResolverB(),
                5000 => new PriorityResolverA(),
            ],
        ]),
    );

    expect($value->container)->toBeInstanceOf(Container::class);

    /** @var Container $container */
    $container = $value->container;
    expect($container->make(PriorityResolvedTarget::class)->value)->toBe('priority-a');
});
