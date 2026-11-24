<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;

/**
 * Marks a class or injection point for virtual-proxy creation through the
 * selected proxy factory. The backing factory is deferred until initialization.
 *
 * The default {@see \Componenta\DI\ProxyFactory} requires a non-static
 * backed instance property, including inherited private properties. An
 * otherwise native-eligible class with no properties, only static properties
 * or only virtual hooks is rejected before allocation with
 * {@see \Componenta\DI\Exception\ResolutionException}. Other native failures
 * retain their existing exception route. Remove the proxy marker for ordinary
 * eager creation, or use an explicit factory / ClassDefinition with its own
 * initialization contract. Custom factory implementations keep their
 * supported mechanisms and the deferred-callback contract.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Proxy implements ParameterSourceAttributeInterface
{
    /** @param class-string|null $class Concrete proxy class for an injection point. */
    public function __construct(public ?string $class = null) {}
}
