<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Value\ValueFallbackRegistry;
use Componenta\DI\Value\ValuePipeline;
use Psr\Container\ContainerInterface;

/** Internal ids owned by the v5 composition root. */
final class ProtectedServiceIds
{
    /** @var array<string,class-string|false> */
    private const array IDS = [
        Config::class => Config::class,
        Environment::class => Environment::class,
        ContainerValue::class => ContainerValue::class,
        Container::class => false,
        ContainerInterface::class => false,
        FactoryInterface::class => false,
        CallableInvokerInterface::class => false,
        CallableResolverInterface::class => false,
        CallableExecutorInterface::class => false,
        ProxyFactoryInterface::class => false,
        LazyObjectFactoryInterface::class => false,
        VirtualProxyFactoryInterface::class => false,
        AttributeDefinitionRegistry::class => AttributeDefinitionRegistry::class,
        AttributePlanBuilder::class => AttributePlanBuilder::class,
        ValueFallbackRegistry::class => ValueFallbackRegistry::class,
        ValuePipeline::class => ValuePipeline::class,
        ParametersResolver::class => ParametersResolver::class,
        ObjectPipeline::class => ObjectPipeline::class,
    ];

    public static function contains(string $id): bool
    {
        return array_key_exists($id, self::IDS);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::IDS);
    }

    /** @return class-string|null */
    public static function bootstrapType(string $id): ?string
    {
        $type = self::IDS[$id] ?? false;
        return is_string($type) ? $type : null;
    }

    private function __construct() {}
}
