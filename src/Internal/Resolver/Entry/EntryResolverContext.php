<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\ReflectionResolver;

/** Filters internal resolution metadata at entry-resolver extension boundaries. @internal */
final class EntryResolverContext
{
    /**
     * @param array<string|int, mixed> $params
     * @return array<string|int, mixed>
     */
    public static function for(EntryResolverInterface $resolver, array $params): array
    {
        return match ($resolver::class) {
            CompositeResolver::class,
            FactoryResolver::class,
            ReflectionResolver::class => $params,
            default => ResolutionMetadata::publicParameters($params),
        };
    }

    private function __construct() {}
}
