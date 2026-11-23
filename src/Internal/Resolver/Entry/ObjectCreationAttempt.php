<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Closure;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;

/**
 * Keeps entry initialization, retries and literal input with the creation pipeline.
 *
 * @internal
 */
final readonly class ObjectCreationAttempt
{
    /**
     * @param Closure(ObjectCreationContext,object):void $initialize
     * @param Closure(ObjectCreationContext,array<string|int,mixed>|(Closure():array<string|int,mixed>)|null):self $retry
     * @param Closure(ObjectCreationContext $context,string $name,mixed &$value):bool $read
     */
    public function __construct(
        public ObjectCreationContext $context,
        private Closure $initialize,
        private Closure $retry,
        private Closure $read,
    ) {}

    public function initialize(object $entry): void
    {
        ($this->initialize)($this->context, $entry);
    }

    /** @param array<string|int,mixed>|(Closure():array<string|int,mixed>)|null $parameters */
    public function retry(array|Closure|null $parameters = null): self
    {
        return ($this->retry)($this->context, $parameters);
    }

    public function readParameter(string $name, mixed &$value): bool
    {
        return ($this->read)($this->context, $name, $value);
    }
}
