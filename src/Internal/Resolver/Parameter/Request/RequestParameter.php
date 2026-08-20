<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Psr\Http\Message\ServerRequestInterface;

/** Internal PSR-7 request transport carried through the ordinary parameter array. @internal */
final class RequestParameter
{
    public const string KEY = ServerRequestInterface::class;

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
