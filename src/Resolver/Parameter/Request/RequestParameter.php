<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Psr\Http\Message\ServerRequestInterface;

/** Helper for accessing PSR-7 request transport state from provided parameters. */
final class RequestParameter
{
    public const string KEY = ServerRequestInterface::class;
    public const string PARAMETER_NAME_ATTRIBUTE = '__parameter_name';

    /** @param array<string|int, mixed> $providedParameters */
    public static function has(array $providedParameters): bool
    {
        return ($providedParameters[self::KEY] ?? null) instanceof ServerRequestInterface;
    }

    /** @param array<string|int, mixed> $providedParameters */
    public static function get(array $providedParameters): ?ServerRequestInterface
    {
        $request = $providedParameters[self::KEY] ?? null;
        return $request instanceof ServerRequestInterface ? $request : null;
    }

    /**
     * @param array<string|int, mixed> $providedParameters
     * @return array<string|int, mixed>
     */
    public static function with(array $providedParameters, ServerRequestInterface $request): array
    {
        $providedParameters[self::KEY] = $request;
        return $providedParameters;
    }

    private function __construct() {}
}
