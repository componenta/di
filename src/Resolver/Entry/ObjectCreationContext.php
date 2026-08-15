<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/** Mutable state of one object-creation operation. */
final class ObjectCreationContext
{
    public private(set) bool $constructorEnabled = true;

    public private(set) CreationStrategy $strategy = CreationStrategy::Eager;

    public private(set) ?object $entry = null;

    /** @var array<string, true> */
    private array $claimedProperties = [];

    /**
     * @param ReflectionClass<object> $class
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

    public function selectStrategy(CreationStrategy $strategy): void
    {
        if ($strategy === CreationStrategy::Eager) {
            if ($this->strategy !== CreationStrategy::Eager) {
                throw $this->conflictingStrategy($strategy);
            }

            return;
        }

        if ($this->strategy === CreationStrategy::Eager) {
            $this->strategy = $strategy;

            return;
        }

        if ($this->strategy !== $strategy) {
            throw $this->conflictingStrategy($strategy);
        }
    }

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

    public function claimProperty(
        ReflectionProperty $property,
        bool $allowPromoted = false,
    ): bool {
        if ($this->entry === null) {
            throw new LogicException(sprintf(
                'Cannot claim property "%s" before "%s" is initialized.',
                $property->getName(),
                $this->class->getName(),
            ));
        }

        if ($property->isStatic()) {
            throw ResolutionException::forProperty(
                $property,
                reason: 'static properties are not supported by DI property handlers',
            );
        }

        if ((!$allowPromoted && $property->isPromoted())
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
            || ($property->isReadOnly() && $property->isInitialized($entry))
        ) {
            throw new LogicException(sprintf(
                'Claimed property "%s::$%s" became unwritable before assignment.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        try {
            $property->setValue($entry, $value);
        } catch (ResolutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    private function conflictingStrategy(CreationStrategy $strategy): InvalidConfigurationException
    {
        return new InvalidConfigurationException(sprintf(
            'Creation strategies "%s" and "%s" cannot be combined for "%s".',
            $this->strategy->value,
            $strategy->value,
            $this->class->getName(),
        ));
    }

    private static function propertyKey(ReflectionProperty $property): string
    {
        return $property->getDeclaringClass()->getName()
            . "\0"
            . $property->getName();
    }
}
