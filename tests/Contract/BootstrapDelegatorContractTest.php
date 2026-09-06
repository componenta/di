<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigProvider;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class BootstrapValue {}

final readonly class BootstrapValueHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(public string $value) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return ParameterAttributeValue::resolved($this->value);
    }
}

final readonly class BootstrapValueResolver implements ParameterResolverInterface
{
    public function __construct(public string $value) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'convention';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): array {
        return [$target->position, $this->value];
    }
}

final readonly class BootstrapValueTarget
{
    public function __construct(
        #[BootstrapValue]
        public string $attribute,
        public string $convention,
    ) {}
}

final class BootstrapExtensionProvider extends ConfigProvider
{
    public function __construct(private readonly bool $useResolverFactory) {}

    protected function getFactories(): array
    {
        return [
            BootstrapValueHandler::class => static fn(): BootstrapValueHandler => new BootstrapValueHandler('attribute'),
            BootstrapValueResolver::class => static fn(): BootstrapValueResolver => new BootstrapValueResolver('resolver'),
        ];
    }

    protected function getAttributeDefinitions(): array
    {
        return [
            static function (ContainerInterface $container): AttributeDefinition {
                $handler = $container->get(BootstrapValueHandler::class);
                if (!$handler instanceof BootstrapValueHandler) {
                    throw new \LogicException('Expected the bootstrap value handler.');
                }

                return new AttributeDefinition(BootstrapValue::class, $handler, [ValueProvider::class]);
            },
        ];
    }

    protected function getParameterResolvers(): array
    {
        return [
            900 => $this->useResolverFactory
                ? static fn(ContainerInterface $container): mixed => $container->get(BootstrapValueResolver::class)
                : BootstrapValueResolver::class,
        ];
    }
}

final class BootstrapDecoratorProvider extends ConfigProvider
{
    protected function getDelegators(): array
    {
        return [
            BootstrapValueHandler::class => [
                static fn(BootstrapValueHandler $handler): BootstrapValueHandler => new BootstrapValueHandler($handler->value . ':decorated'),
            ],
            BootstrapValueResolver::class => [
                static fn(BootstrapValueResolver $resolver): BootstrapValueResolver => new BootstrapValueResolver($resolver->value . ':first'),
                static fn(BootstrapValueResolver $resolver): BootstrapValueResolver => new BootstrapValueResolver($resolver->value . ':second'),
            ],
        ];
    }
}

test('bootstrap extensions use the delegators composed by all providers', function (
    bool $decoratorsFirst,
    bool $useResolverFactory,
): void {
    $providers = [new BootstrapExtensionProvider($useResolverFactory), new BootstrapDecoratorProvider()];
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        ...($decoratorsFirst ? array_reverse($providers) : $providers),
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies)->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make(BootstrapValueTarget::class);

    expect($target->attribute)->toBe('attribute:decorated')
        ->and($target->convention)->toBe('resolver:first:second')
        ->and($container->get(BootstrapValueHandler::class)->value)->toBe($target->attribute)
        ->and($container->get(BootstrapValueResolver::class)->value)->toBe($target->convention);
})->with([
    'extensions first, resolver service' => [false, false],
    'decorators first, resolver service' => [true, false],
    'extensions first, resolver factory' => [false, true],
    'decorators first, resolver factory' => [true, true],
]);
