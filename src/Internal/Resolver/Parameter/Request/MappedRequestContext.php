<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

/**
 * Transformed field names owned by one HTTP DTO mapping.
 *
 * @internal
 */
final readonly class MappedRequestContext
{
    /** @var array<string,true> */
    private array $keys;

    /** @param array<string|int,mixed> $mappedData */
    public function __construct(array $mappedData)
    {
        $keys = [];
        foreach ($mappedData as $key => $_value) {
            if (is_string($key)) {
                $keys[$key] = true;
            }
        }
        $this->keys = $keys;
    }

    public function contains(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function without(string|int $key): self
    {
        if (!isset($this->keys[$key])) {
            return $this;
        }
        $keys = $this->keys;
        unset($keys[$key]);
        return new self($keys);
    }
}
