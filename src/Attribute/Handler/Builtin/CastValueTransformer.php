<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Handler\ValueTransformerHandlerInterface;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class CastValueTransformer implements ValueTransformerHandlerInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function transform(object $attribute, mixed $value, ValueTargetInterface $target, ValueContext $context): mixed
    {
        if (!$attribute instanceof Cast) {
            throw new LogicException('CastValueTransformer received an unsupported attribute.');
        }
        if (!$this->container->has(CasterProviderInterface::class)) {
            throw new LogicException(sprintf('Caster provider %s is not configured.', CasterProviderInterface::class));
        }
        $provider = $this->container->get(CasterProviderInterface::class);
        if (!$provider instanceof CasterProviderInterface) {
            throw new LogicException(sprintf('Container entry %s has an invalid type.', CasterProviderInterface::class));
        }
        $caster = $provider->provide($attribute->name)
            ?? throw new LogicException(sprintf('Caster "%s" is not registered.', $attribute->name));

        return $caster->cast($value);
    }
}
