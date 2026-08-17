<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;

/** @internal */
final class EntryResolverContext
{
    /**
     * Preserves DI-owned mapped provenance only for exact built-in resolvers.
     * User resolvers and subclasses receive the caller-visible context only.
     *
     * @param array<string|int, mixed> $context
     * @return array<string|int, mixed>
     */
    public static function for(
        EntryResolverInterface $resolver,
        array $context,
    ): array {
        return match ($resolver::class) {
            CompositeResolver::class,
            FactoryResolver::class,
            ReflectionResolver::class => $context,
            default => MappedRequestContext::strip($context),
        };
    }

    private function __construct() {}
}
