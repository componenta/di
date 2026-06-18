<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Helper for accessing PSR-7 request from provided parameters.
 *
 * Resolvers use this to extract the request instance from the
 * $providedParameters array passed during resolution.
 *
 * @example Adding request to parameters
 * ```php
 * $params = RequestParameter::with($params, $request);
 * $resolver->resolveAll($method, $params);
 * ```
 *
 * @example Checking and retrieving request in resolver
 * ```php
 * $request = RequestParameter::get($providedParameters);
 * if ($request === null) {
 *     throw ResolutionException::forParameter(...);
 * }
 * ```
 */
final class RequestParameter
{
    /**
     * Key used to store the request in providedParameters.
     */
    public const string KEY = ServerRequestInterface::class;

    /**
     * Checks if a request exists in the provided parameters.
     */
    public static function has(array $providedParameters): bool
    {
        return isset($providedParameters[self::KEY])
            && $providedParameters[self::KEY] instanceof ServerRequestInterface;
    }

    /**
     * Retrieves the request from provided parameters.
     *
     * @param array<string|int, mixed> $providedParameters The parameters array.
     * @return ServerRequestInterface|null The request or null if not present.
     */
    public static function get(array $providedParameters): ?ServerRequestInterface
    {
        return self::has($providedParameters) ? $providedParameters[self::KEY] : null;
    }

    /**
     * Adds a request to the provided parameters array.
     *
     * @param array<string|int, mixed> $providedParameters The parameters array.
     * @param ServerRequestInterface $request The PSR-7 request.
     * @return array<string|int, mixed> The updated parameters array.
     */
    public static function with(array $providedParameters, ServerRequestInterface $request): array
    {
        $providedParameters[self::KEY] = $request;
        return $providedParameters;
    }
}
