<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Mutable state of one object-creation operation.
 *
 * Inputs are readonly. Handlers may change only controlled lifecycle state or
 * write an explicitly attributed property through {@see writeProperty()}.
 */
final class ObjectCreationContext
{
    public private(set) bool $constructorEnabled = true;

    public private(set) CreationStrategy $strategy = CreationStrategy::Eager;

    public private(set) ?object $entry = null;

    /** @var array<string, true> */
    private array $claimedProperties = [];

    /**
     * @param array<string|int, mixed> $parameters
     */
    public function __construct(
        public readonly ReflectionClass $class,
        public readonly array $parameters = [],
    ) {}

    public function disableConstructor(): void
    {
        $this->constructorEnabled = false;
    }

    /**
     * Selects the first non-eager strategy in handler-priority order.
     */
    public function selectStrategy(CreationStrategy $strategy): bool
    {
        if ($strategy === CreationStrategy::Eager) {
            return $this->strategy === CreationStrategy::Eager;
        }

        if ($this->strategy !== CreationStrategy::Eager) {
            return false;
        }

        $this->strategy = $strategy;

        return true;
    }

    /**
     * Creates isolated mutable state for one lazy/proxy realization attempt.
     *
     * PHP retries a lazy initializer after an exception. Lifecycle decisions
     * are preserved, while the entry and property claims from a failed attempt
     * must never leak into the next one.
     */
    public function freshAttempt(): self
    {
        $attempt = clone $this;
        $attempt->entry = null;
        $attempt->claimedProperties = [];

        return $attempt;
    }

    public function initialize(object $entry): void
    {
        if ($this->entry !== null) {
            throw new LogicException(sprintf(
                'Object creation context for "%s" is already initialized.',
                $this->class->getName(),
            ));
        }

        if (!$entry instanceof ($this->class->getName())) {
            throw new LogicException(sprintf(
                'Expected an instance of "%s", got "%s".',
                $this->class->getName(),
                $entry::class,
            ));
        }

        $this->entry = $entry;
    }

    /**
     * Claims a property for the highest-priority applicable handler.
     *
     * Claiming happens before value resolution so lower-priority handlers can
     * never perform container lookups, casting or factory calls for an already
     * owned target.
     */
    public function claimProperty(ReflectionProperty $property): bool
    {
        if ($this->entry === null) {
            throw new LogicException(sprintf(
                'Cannot claim property "%s" before "%s" is initialized.',
                $property->getName(),
                $this->class->getName(),
            ));
        }

        if ($property->isStatic()
            || $property->isPromoted()
            || ($property->isReadOnly() && $property->isInitialized($this->entry))
        ) {
            return false;
        }

        $key = self::propertyKey($property);

        if (isset($this->claimedProperties[$key])) {
            return false;
        }

        $this->claimedProperties[$key] = true;

        return true;
    }

    /**
     * Writes a value through normal PHP property semantics.
     *
     * The caller must claim the property first. ReflectionProperty::setValue()
     * intentionally invokes PHP 8.4 property hooks; raw writes are never used.
     */
    public function writeProperty(ReflectionProperty $property, mixed $value): void
    {
        if (!isset($this->claimedProperties[self::propertyKey($property)])) {
            throw new LogicException(sprintf(
                'Property "%s::$%s" must be claimed before it is written.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        $entry = $this->entry ?? throw new LogicException(sprintf(
            'Cannot write property "%s" before "%s" is initialized.',
            $property->getName(),
            $this->class->getName(),
        ));

        if ($property->isStatic()
            || $property->isPromoted()
            || ($property->isReadOnly() && $property->isInitialized($entry))
        ) {
            throw new LogicException(sprintf(
                'Claimed property "%s::$%s" became unwritable before assignment.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        try {
            // Normal reflection assignment intentionally invokes PHP 8.4
            // property hooks. Raw writes would silently bypass user code.
            $property->setValue($entry, $value);
        } catch (ResolutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    private static function propertyKey(ReflectionProperty $property): string
    {
        return $property->getDeclaringClass()->getName()
            . "\0"
            . $property->getName();
    }
}
