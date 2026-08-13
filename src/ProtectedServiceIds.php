<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Psr\Container\ContainerInterface;

/** Single internal source of ids owned by the DI composition root. */
final class ProtectedServiceIds
{
    /**
     * A class-string value means that the id may be supplied through the
     * constructor bootstrap map and must satisfy that type. `false` marks an
     * id that is installed directly by Container itself.
     *
     * @var array<string, class-string|false>
     */
    private const array IDS = [
        'config' => false,
        Config::class => Config::class,
        Environment::class => Environment::class,
        ContainerValue::class => ContainerValue::class,
        Container::class => false,
        ContainerInterface::class => false,
        FactoryInterface::class => false,
        CallableInvokerInterface::class => false,
        CallableResolverInterface::class => false,
        AliasResolverInterface::class => false,
        CallableExecutorInterface::class => false,
        ProxyFactoryInterface::class => false,
        LazyObjectFactoryInterface::class => false,
        VirtualProxyFactoryInterface::class => false,
        ParametersResolver::class => ParametersResolver::class,
        AttributeHandlerRegistry::class => AttributeHandlerRegistry::class,
        AttributeProcessor::class => AttributeProcessor::class,
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
