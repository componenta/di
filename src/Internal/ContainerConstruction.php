<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use ReflectionClass;

/** Creates a container with collaborators owned by its assembly. @internal */
abstract class ContainerConstruction extends ContainerEntryOperations
{
    /** @param array<array-key,mixed> $bootstrapServices */
    public static function create(
        EntryResolverInterface $resolver,
        AliasResolver $aliases,
        CallableExecutorInterface $callableExecutor,
        ?EntryCache $cache = null,
        ?DelegatorRegistry $delegators = null,
        ?ExternalContainerRegistry $externalContainers = null,
        ?CycleGuard $cycleGuard = null,
        ?ProxyFactoryInterface $proxyFactory = null,
        array $bootstrapServices = [],
    ): Container {
        $container = new ReflectionClass(Container::class)->newInstanceWithoutConstructor();
        $container->assemble($resolver, $aliases, $callableExecutor, $cache, $delegators, $externalContainers, $cycleGuard, $proxyFactory, $bootstrapServices);
        return $container;
    }

    public static function defer(
        ContainerBootstrapState $bootstrap,
        AliasResolver $aliases,
        EntryCache $cache,
        ProxyFactoryInterface $proxies,
        Config $config,
        ParametersResolverInterface $parametersResolver,
    ): Container {
        return new ReflectionClass(Container::class)->newLazyGhost(
            static function (Container $container) use ($bootstrap, $aliases, $cache, $proxies, $config, $parametersResolver): void {
                $container->assemble(
                    $bootstrap->entryResolver,
                    $aliases,
                    $bootstrap->callableExecutor,
                    cache: $cache,
                    delegators: new DelegatorRegistry($container, $container),
                    proxyFactory: $proxies,
                    bootstrapServices: [
                        Config::class => $config,
                        Environment::class => $config->environment,
                        ContainerValue::class => new ContainerValue($container, $config),
                        ParametersResolverInterface::class => $parametersResolver,
                    ],
                );
            },
        );
    }
}
