<?php

declare(strict_types=1);

namespace Componenta\DI;

use Psr\Container\ContainerInterface;

/**
 * Marks an application factory as capable of producing its service in a
 * lazy form.
 *
 * A factory class implementing this interface offers two construction
 * paths:
 *
 *  - The usual `__invoke(ContainerInterface): object` - eager
 *    construction (called when the container resolves the entry without
 *    a lazy strategy).
 *  - {@see lazy()} - lazy construction. Called by the container when the
 *    entry should be returned as a lazy object or virtual proxy. The
 *    factory itself decides which strategy fits its product and uses
 *    the supplied {@see ProxyFactoryInterface} to produce the wrapper.
 *
 * ## When to implement
 *
 * Implement this interface on factories whose product:
 *
 *  - Has expensive construction cost (deep dependency graph, schema
 *    introspection, network handshake, etc.) and is not used by every
 *    request.
 *  - Cannot be marked with {@see Attribute\Proxy} or {@see Attribute\Lazy}
 *    declaratively because the strategy needs custom logic - for example
 *    when the product is a vendor object whose constructor is not
 *    directly callable.
 *
 * For services with a simple `new Foo($a, $b)` factory body, prefer the
 * declarative {@see Attribute\Lazy} attribute on the product class - it
 * keeps the factory free of laziness concerns.
 *
 * ## Example - opaque vendor factory
 *
 * ```php
 * final class DatabaseFactory implements LazyServiceFactoryInterface
 * {
 *     public function __invoke(ContainerInterface $c): DatabaseInterface
 *     {
 *         return $c->get(DatabaseProviderInterface::class)->database();
 *     }
 *
 *     public function lazy(ContainerInterface $c, ProxyFactoryInterface $pf): object
 *     {
 *         // Ghost is impossible: the product is built by an opaque vendor
 *         // call returning some Database subclass. Virtual proxy fits.
 *         return $pf->makeProxy(
 *             DatabaseInterface::class,
 *             fn (object $proxy): DatabaseInterface => $this->__invoke($c),
 *         );
 *     }
 * }
 * ```
 *
 * ## Example - known concrete class
 *
 * ```php
 * final class PostFetcherFactory implements LazyServiceFactoryInterface
 * {
 *     public function __invoke(ContainerInterface $c): PostFetcher
 *     {
 *         return new PostFetcher(
 *             $c->get(DatabaseInterface::class),
 *             $c->get(CasterProviderInterface::class),
 *         );
 *     }
 *
 *     public function lazy(ContainerInterface $c, ProxyFactoryInterface $pf): object
 *     {
 *         // Concrete class with a normal constructor - ghost preserves
 *         // get_class() identity and is the better choice.
 *         return $pf->makeLazy(
 *             PostFetcher::class,
 *             fn (object $instance) => $instance->__construct(
 *                 $c->get(DatabaseInterface::class),
 *                 $c->get(CasterProviderInterface::class),
 *             ),
 *         );
 *     }
 * }
 * ```
 */
interface LazyServiceFactoryInterface
{
    /**
     * Produces the service in a lazy form.
     *
     * @param ContainerInterface     $container     Container, for resolving
     *                                              dependencies inside the
     *                                              wrapper's initializer.
     * @param ProxyFactoryInterface  $proxyFactory  Used to build the lazy
     *                                              wrapper; pick
     *                                              {@see ProxyFactoryInterface::makeLazy()}
     *                                              for ghost or
     *                                              {@see ProxyFactoryInterface::makeProxy()}
     *                                              for virtual proxy
     *                                              depending on what fits
     *                                              the product.
     *
     * @return object Lazy wrapper that is-a service type.
     */
    public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory): object;
}
