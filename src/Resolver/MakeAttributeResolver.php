<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\ProxyFactory;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionParameter;
use ReflectionProperty;
use Throwable;

/**
 * Creates instances via {@see FactoryInterface} for parameters or properties
 * marked with {@see \Componenta\DI\Attribute\Make} or {@see \Componenta\DI\Attribute\Proxy}.
 *
 * Resolution triggers:
 * - `#[Make]` - creates a new instance via the factory.
 * - `#[Proxy]` - wraps the resolved entry in a virtual proxy, deferring
 *               construction to first observable access.
 *
 * Entry resolution order (when `Make->entry` is null):
 * 1. Target type if it's a class or interface.
 * 2. Target name otherwise.
 *
 * @example Parameter: basic factory creation
 * ```php
 * function process(#[Make(UserDTO::class)] UserDTO $user) {}
 * ```
 *
 * @example Property: with constructor parameters
 * ```php
 * class Service {
 *     #[Make(PdfRenderer::class, params: ['format' => 'A4'])]
 *     private PdfRenderer $renderer;
 * }
 * ```
 *
 * @example Virtual proxy at injection point
 * ```php
 * function process(#[Proxy] HeavyService $service) {}
 * ```
 */
final readonly class MakeAttributeResolver implements
    ParameterResolverInterface,
    PropertyResolverInterface,
    AttributeDrivenResolverInterface,
    AttributeMatcherInterface
{
    public const string KIND = 'componenta.di.make';

    private FactoryConfigReader $configReader;
    private ProxyFactoryInterface $proxyFactory;

    public function __construct(
        private FactoryInterface $factory,
        ?FactoryConfigReader $configReader = null,
        ?ProxyFactoryInterface $proxyFactory = null,
    ) {
        $this->configReader = $configReader ?? new FactoryConfigReader();
        $this->proxyFactory = $proxyFactory
            ?? ($factory instanceof ProxyFactoryInterface ? $factory : new ProxyFactory());
    }

    public function planKind(): string
    {
        return self::KIND;
    }

    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
    {
        if ($target->getAttributes(Make::class) !== []
            || $target->getAttributes(Proxy::class) !== []
        ) {
            return self::KIND;
        }
        return null;
    }

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $config = $this->configReader->read($parameter);
        if ($config === null) {
            return null;
        }

        try {
            $instance = $config['proxy']
                ? $this->proxyFactory->makeProxy(
                    $config['entry'],
                    fn(object $proxy): object => $this->factory->make($config['entry'], $config['params']),
                )
                : $this->factory->make($config['entry'], $config['params']);

            return [$parameter->getPosition(), $instance];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $parameter,
                previous: $e,
                providedParameters: $providedParameters,
                resolvedParameters: $resolvedParameters,
            );
        }
    }

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $config = $this->configReader->read($property);
        if ($config === null) {
            return null;
        }

        try {
            $instance = $config['proxy']
                ? $this->proxyFactory->makeProxy(
                    $config['entry'],
                    fn(object $proxy): object => $this->factory->make($config['entry'], $config['params']),
                )
                : $this->factory->make($config['entry'], $config['params']);

            return [$property, $instance];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }
}
