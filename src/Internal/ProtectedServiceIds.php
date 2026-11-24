<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Container;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\VirtualProxyFactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Reserved ids owned by the composition root.
 *
 * @internal
 */
final class ProtectedServiceIds
{
    private const array AUTOWIRING_EXCLUDED = [
        DependencyDefinitions::class => false,
        RequestAttributeHandler::class => false,
        AttributeDefinitionRegistry::class => false,
        AttributePlanBuilder::class => false,
        AttributeProcessor::class => false,
        ParametersResolver::class => false,
        ObjectPipeline::class => false,
    ];

    /** @var array<string,class-string|false> */
    private const array IDS = [
        ConfigAttribute::KEY => false,
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
        ...self::AUTOWIRING_EXCLUDED,
    ];

    /** @return list<class-string> */
    public static function autowiringExcluded(): array
    {
        return array_keys(self::AUTOWIRING_EXCLUDED);
    }

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
