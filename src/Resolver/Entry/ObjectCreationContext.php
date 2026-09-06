<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Closure;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Object\CreationStrategy;
use LogicException;
use PropertyHookType;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/** Mutable state owned by exactly one object-creation attempt. */
final class ObjectCreationContext
{
    public private(set) bool $constructorEnabled = true;
    public private(set) CreationStrategy $strategy = CreationStrategy::Eager;
    public private(set) ?object $entry = null;

    /** @var array<string|int,mixed> */
    public array $parameters {
        get {
            $parameters = $this->parameterValues;
            if ($parameters instanceof Closure) {
                $parameters = $parameters();
                $this->parameterValues = $parameters;
            }

            return $parameters;
        }
    }

    /** @var array<string|int,mixed>|Closure():array<string|int,mixed> */
    private array|Closure $parameterValues;

    /** @var array<string,true> */
    private array $claimedProperties = [];

    /** @var array<string,array{value?:mixed}> */
    private array $pendingPropertyWrites = [];

    /**
     * @param ReflectionClass<object> $class
     * @param array<string|int,mixed>|Closure():array<string|int,mixed> $parameters Caller-visible parameters only.
     */
    public function __construct(
        public readonly ReflectionClass $class,
        array|Closure $parameters = [],
    ) {
        $this->parameterValues = $parameters;
    }

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

    /** @param array<string|int,mixed>|(Closure():array<string|int,mixed>)|null $parameters */
    public function freshAttempt(array|Closure|null $parameters = null): self
    {
        $attempt = clone $this;
        if ($parameters !== null) {
            $attempt->parameterValues = $parameters;
        }
        $attempt->entry = null;
        $attempt->claimedProperties = [];
        $attempt->pendingPropertyWrites = [];
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

        $className = $this->class->getName();
        if (!$entry instanceof $className) {
            throw new LogicException(sprintf(
                'Expected an instance of "%s", got "%s".',
                $className,
                $entry::class,
            ));
        }

        $this->entry = $entry;
    }

    public function claimProperty(ReflectionProperty $property, bool $allowPromoted = false): bool
    {
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

        if ($property->isVirtual() && !$property->hasHook(PropertyHookType::Set)) {
            throw ResolutionException::forProperty(
                $property,
                reason: 'virtual properties without a set hook are not writable by DI property handlers',
            );
        }

        if ((!$allowPromoted && $property->isPromoted() && $this->constructorEnabled)
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

    public function propertyClaimed(ReflectionProperty $property): bool
    {
        return isset($this->claimedProperties[self::propertyKey($property)]);
    }

    /**
     * Keeps intermediate values outside the typed property until its handlers succeed.
     *
     * @param Closure():void $resolve
     */
    public function resolveProperty(ReflectionProperty $property, Closure $resolve): void
    {
        $key = self::propertyKey($property);
        $this->pendingPropertyWrites[$key] = [];

        try {
            $resolve();
            $pending = $this->pendingPropertyWrites[$key];
        } finally {
            unset($this->pendingPropertyWrites[$key]);
        }

        if (array_key_exists('value', $pending)) {
            $this->writeProperty($property, $pending['value']);
        }
    }

    public function readProperty(ReflectionProperty $property): mixed
    {
        $entry = $this->entry ?? throw new LogicException(sprintf(
            'Cannot read property "%s" before "%s" is initialized.',
            $property->getName(),
            $this->class->getName(),
        ));

        $pending = $this->pendingPropertyWrites[self::propertyKey($property)] ?? [];
        if (array_key_exists('value', $pending)) {
            return $pending['value'];
        }

        if ($property->isVirtual() && !$property->hasHook(PropertyHookType::Get)) {
            throw ResolutionException::forProperty(
                $property,
                reason: 'write-only virtual properties cannot be read by DI property handlers',
            );
        }

        return $property->isInitialized($entry)
            ? $property->getValue($entry)
            : null;
    }

    public function writeProperty(ReflectionProperty $property, mixed $value): void
    {
        if (!$this->propertyClaimed($property)) {
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

        $key = self::propertyKey($property);
        if (isset($this->pendingPropertyWrites[$key])) {
            $this->pendingPropertyWrites[$key]['value'] = $value;
            return;
        }

        try {
            $property->setValue($entry, $value);
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    private function conflictingStrategy(CreationStrategy $strategy): InvalidConfigurationException
    {
        return new InvalidConfigurationException(sprintf(
            'Creation strategies "%s" and "%s" cannot be combined for "%s".',
            $this->strategy->name,
            $strategy->name,
            $this->class->getName(),
        ));
    }

    private static function propertyKey(ReflectionProperty $property): string
    {
        return $property->getDeclaringClass()->getName() . "\0" . $property->getName();
    }
}
