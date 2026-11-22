<?php

declare(strict_types=1);

namespace Componenta\DI\Configuration;

use Componenta\DI\Internal\DependencyNormalizer;

/**
 * Validates and normalizes application dependency definitions.
 * @phpstan-import-type DependencyShape from DependencyNormalizer
 */
final class DependencyConfiguration
{
    private function __construct() {}

    /**
     * @param array<array-key,mixed> $dependencies
     * @param array<string,non-empty-string> $defaultAliases
     * @return DependencyShape
     */
    public static function normalize(array $dependencies, array $defaultAliases = []): array
    {
        return DependencyNormalizer::normalize($dependencies, $defaultAliases);
    }

    /**
     * Replaces the supplied array with canonical values after validation succeeds.
     * @param array<array-key,mixed> $dependencies
     */
    public static function assertShape(array &$dependencies): void
    {
        DependencyNormalizer::assertShape($dependencies);
    }
}
