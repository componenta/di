<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

final class EnvNameNormalizer
{
    public static function toEnvName(string $name): string
    {
        $snakeCase = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);

        return strtoupper($snakeCase ?? $name);
    }
}
