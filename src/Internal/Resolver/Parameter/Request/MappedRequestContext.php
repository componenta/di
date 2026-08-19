<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

/** Carries transformed HTTP DTO provenance through nested make(). @internal */
final readonly class MappedRequestContext
{
    private const string KEY = "\0componenta.di.mapped-request";

    /** @param array<string,true> $keys */
    private function __construct(private array $keys) {}

    /**
     * @param array<string|int,mixed> $context
     * @param array<string|int,mixed> $mappedData
     * @return array<string|int,mixed>
     */
    public static function with(array $context, array $mappedData): array
    {
        $keys = [];
        foreach ($mappedData as $key => $_value) {
            if (is_string($key)) {
                $keys[$key] = true;
            }
        }
        $context[self::KEY] = new self($keys);
        return $context;
    }

    /** @param array<string|int,mixed> $context */
    public static function get(array $context): ?self
    {
        $provenance = $context[self::KEY] ?? null;
        return $provenance instanceof self ? $provenance : null;
    }

    /**
     * @param array<string|int,mixed> $context
     * @return array<string|int,mixed>
     */
    public static function strip(array $context): array
    {
        unset($context[self::KEY]);
        return $context;
    }

    public function contains(string $key): bool
    {
        return isset($this->keys[$key]);
    }
}
