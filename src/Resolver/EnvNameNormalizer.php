<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

/**
 * Converts a PHP identifier (parameter or property name) into the corresponding
 * environment variable name.
 *
 * Lives outside {@see \Componenta\DI\Attribute\Env} so the attribute can stay a
 * pure DTO; the same normalisation is shared by every consumer that needs to
 * derive an env-var name from an implicit `#[Env]` target name.
 *
 * @internal
 */
final class EnvNameNormalizer
{
    /**
     * camelCase / PascalCase -> UPPER_SNAKE_CASE.
     *
     * `databaseHost` -> `DATABASE_HOST`
     * `appDebug`     -> `APP_DEBUG`
     */
    public static function toEnvName(string $name): string
    {
        $snakeCase = preg_replace('/([a-z])([A-Z])/', '$1_$2', $name);

        return strtoupper($snakeCase);
    }
}
