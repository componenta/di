<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Closure;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\ReflectionSnapshot;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationAttempt;
use Componenta\DI\Internal\Resolver\Entry\ObjectCreationScope;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Fiber;
use LogicException;
use PropertyHookType;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;
use WeakReference;

/** Mutable state owned by exactly one object-creation attempt. */
final class ObjectCreationContext extends ObjectCreationScope
{
    /** @var (Closure(self,object):void)|null */
    private static ?Closure $initializeAttempt = null;

    /** @var (Closure(self,array<string|int,mixed>|(Closure():array<string|int,mixed>)|null):ObjectCreationAttempt)|null */
    private static ?Closure $retryAttempt = null;

    private bool $constructorPolicyLocked = false;
    private bool $propertyWritesLocked = false;
    private bool $processingPhase = false;
    /** @var WeakReference<object>|null */
    private readonly ?WeakReference $constructorOwner;
    /** @var WeakReference<object>|null */
    private ?WeakReference $propertyOwner = null;
    /** @var ReflectionClass<covariant object> */
    private readonly ReflectionClass $declaration;

    /** @var ReflectionClass<covariant object> */
    public ReflectionClass $class {
        get => ReflectionSnapshot::class($this->declaration);
    }

    public private(set) bool $constructorEnabled = true;
    public private(set) CreationStrategy $strategy = CreationStrategy::Eager;
    public private(set) ?object $entry = null;

    /** @var array<string|int,mixed> */
    public array $parameters {
        get => ResolutionMetadata::snapshot($this->readParameterValues());
    }

    /** @var array<string|int,mixed>|Closure():array<string|int,mixed> */
    private array|Closure $parameterValues;
    /** @var (Closure(self):void)|null */
    private ?Closure $beforeParameterRead = null;

    /** @var array<string,true> */
    private array $claimedProperties = [];

    /** @var array<string,array{value?:mixed}> */
    private array $pendingPropertyWrites = [];

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int,mixed>|Closure():array<string|int,mixed> $parameters Caller-visible parameters only.
     */
    private function __construct(
        ReflectionClass $class,
        array|Closure $parameters = [],
    ) {
        $this->declaration = ReflectionSnapshot::forConstruction($class);
        $this->parameterValues = $parameters instanceof Closure
            ? $parameters
            : ResolutionMetadata::invocationSlots($parameters);
        $this->constructorOwner = self::executionOwner();
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $class
     * @param array<string|int,mixed>|Closure():array<string|int,mixed> $parameters
     * @param (Closure(self):void)|null $beforeParameterRead
     */
    protected static function startAttempt(
        ReflectionClass $class,
        array|Closure $parameters,
        ?Closure $beforeParameterRead,
    ): ObjectCreationAttempt {
        $context = new self($class, $parameters);
        $context->beforeParameterRead = $beforeParameterRead;
        return self::owned($context);
    }

    private static function owned(self $context): ObjectCreationAttempt
    {
        return new ObjectCreationAttempt(
            $context,
            self::$initializeAttempt ??= static function (self $context, object $entry): void {
                $context->initialize($entry);
            },
            self::$retryAttempt ??= static fn(self $context, array|Closure|null $parameters): ObjectCreationAttempt
                => self::owned($context->freshAttempt($parameters)),
        );
    }

    /** @return array<string|int,mixed> */
    private function readParameterValues(): array
    {
        $this->assertParametersReadable();
        $parameters = $this->parameterValues;
        if ($parameters instanceof Closure) {
            $parameters = ResolutionMetadata::invocationSlots($parameters());
            $this->parameterValues = $parameters;
        }

        return $parameters;
    }

    protected function findParameter(string $name, mixed &$value): bool
    {
        $parameters = $this->readParameterValues();
        if (!array_key_exists($name, $parameters)) {
            return false;
        }

        $value = $parameters[$name];
        return true;
    }

    protected function assertParametersReadable(): void
    {
        if ($this->parameterValues instanceof Closure) {
            if ($this->entry === null) {
                $this->assertConstructorPolicyOpen();
            } else {
                $this->assertPropertyWritesOpen();
            }
        }
        $this->beforeParameterRead?->__invoke($this);
    }

    /**
     * @template T
     * @param Closure():T $dispatch
     * @return T
     */
    protected function runPhase(AttributePhase $phase, Closure $dispatch): mixed
    {
        if ($phase === AttributePhase::Both) {
            throw new LogicException('Object creation requires a single phase.');
        }
        if ($this->processingPhase) {
            throw new LogicException('An object creation phase is already running.');
        }
        $before = $phase === AttributePhase::BeforeInstantiation;
        if ($before) {
            $this->assertConstructorPolicyOpen();
        } else {
            if ($this->entry === null) {
                throw new LogicException('The object property phase requires an initialized entry.');
            }
            $this->assertPropertyWritesOpen();
        }

        $this->processingPhase = true;
        try {
            return $dispatch();
        } finally {
            $this->processingPhase = false;
            if ($before) {
                $this->constructorPolicyLocked = true;
            } else {
                $this->propertyWritesLocked = true;
            }
        }
    }

    public function disableConstructor(): void
    {
        $this->assertConstructorPolicyOpen();
        $this->constructorEnabled = false;
    }

    public function selectStrategy(CreationStrategy $strategy): void
    {
        $this->assertConstructorPolicyOpen();
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

    private function assertConstructorPolicyOpen(): void
    {
        if ($this->constructorPolicyLocked) {
            throw new LogicException('Object creation policy is fixed after the before-instantiation phase.');
        }
        self::assertExecutionOwner($this->constructorOwner);
    }

    protected function assertPropertyWritesOpen(): void
    {
        if ($this->propertyWritesLocked) {
            throw new LogicException('The object property phase has completed.');
        }
        self::assertExecutionOwner($this->entry === null ? $this->constructorOwner : $this->propertyOwner);
    }

    /** @return WeakReference<object>|null */
    private static function executionOwner(): ?WeakReference
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? null : WeakReference::create($fiber);
    }

    /** @param WeakReference<object>|null $owner */
    private static function assertExecutionOwner(?WeakReference $owner): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null ? $owner !== null : $owner?->get() !== $fiber) {
            throw new LogicException('This phase belongs to another execution.');
        }
    }

    /** @param array<string|int,mixed>|(Closure():array<string|int,mixed>)|null $parameters */
    private function freshAttempt(array|Closure|null $parameters): self
    {
        $attempt = clone $this;
        if ($parameters !== null) {
            $attempt->parameterValues = $parameters instanceof Closure
                ? $parameters
                : ResolutionMetadata::invocationSlots($parameters);
        }
        $attempt->entry = null;
        $attempt->propertyOwner = null;
        $attempt->propertyWritesLocked = false;
        $attempt->claimedProperties = [];
        $attempt->pendingPropertyWrites = [];
        return $attempt;
    }

    private function initialize(object $entry): void
    {
        if ($this->entry !== null) {
            throw new LogicException(sprintf(
                'Object creation context for "%s" is already initialized.',
                $this->declaration->getName(),
            ));
        }

        $className = $this->declaration->getName();
        if (!$entry instanceof $className) {
            throw new LogicException(sprintf(
                'Expected an instance of "%s", got "%s".',
                $className,
                $entry::class,
            ));
        }

        $this->entry = $entry;
        $this->propertyOwner = self::executionOwner();
        $this->constructorPolicyLocked = true;
    }

    public function claimProperty(ReflectionProperty $property, bool $allowPromoted = false): bool
    {
        $this->assertPropertyWritesOpen();
        if ($this->entry === null) {
            throw new LogicException(sprintf(
                'Cannot claim property "%s" before "%s" is initialized.',
                $property->getName(),
                $this->declaration->getName(),
            ));
        }

        $key = $this->propertyKey($property);
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

        if (isset($this->claimedProperties[$key])) {
            return false;
        }

        $this->claimedProperties[$key] = true;
        return true;
    }

    public function propertyClaimed(ReflectionProperty $property): bool
    {
        return isset($this->claimedProperties[$this->propertyKey($property)]);
    }

    /**
     * Keeps intermediate values outside the typed property until its handlers succeed.
     *
     * @param Closure():void $resolve
     * @throws LogicException If the same property is already being resolved.
     */
    protected function resolveProperty(ReflectionProperty $property, Closure $resolve, ?ReflectionProperty $declaration): void
    {
        $this->assertPropertyWritesOpen();
        $key = $this->propertyKey($declaration ?? $property);
        if (isset($this->pendingPropertyWrites[$key])) {
            throw new LogicException(sprintf(
                'Property "%s::$%s" is already being resolved.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        $this->pendingPropertyWrites[$key] = [];

        try {
            $resolve();
            $pending = $this->pendingPropertyWrites[$key];
        } finally {
            unset($this->pendingPropertyWrites[$key]);
        }

        if (array_key_exists('value', $pending)) {
            $writer = $declaration === null || ReflectionSnapshot::sameDeclaration($property, $declaration)
                ? $property
                : $declaration;
            $this->writeProperty($writer, $pending['value']);
        }
    }

    public function readProperty(ReflectionProperty $property): mixed
    {
        $entry = $this->entry ?? throw new LogicException(sprintf(
            'Cannot read property "%s" before "%s" is initialized.',
            $property->getName(),
            $this->declaration->getName(),
        ));

        $pending = $this->pendingPropertyWrites[$this->propertyKey($property)] ?? [];
        if (array_key_exists('value', $pending)) {
            return $pending['value'];
        }

        if ($property->isVirtual() && !$property->hasHook(PropertyHookType::Get)) {
            throw ResolutionException::forProperty(
                $property,
                reason: 'write-only virtual properties cannot be read by DI property handlers',
            );
        }

        // A getter can compute a value before its backing slot is initialized.
        return $property->isInitialized($entry) || $property->hasHook(PropertyHookType::Get)
            ? $property->getValue($entry)
            : null;
    }

    public function writeProperty(ReflectionProperty $property, mixed $value): void
    {
        $this->assertPropertyWritesOpen();
        $key = $this->propertyKey($property);
        if (!isset($this->claimedProperties[$key])) {
            throw new LogicException(sprintf(
                'Property "%s::$%s" must be claimed before it is written.',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
            ));
        }

        $entry = $this->entry ?? throw new LogicException(sprintf(
            'Cannot write property "%s" before "%s" is initialized.',
            $property->getName(),
            $this->declaration->getName(),
        ));

        if (isset($this->pendingPropertyWrites[$key])) {
            $this->pendingPropertyWrites[$key]['value'] = $value;
            return;
        }

        try {
            $writer = $property;
            if ($property::class !== ReflectionProperty::class
                && $property->isPrivateSet()
                && new ReflectionMethod($property, 'setValue')->getDeclaringClass()->getName() === ReflectionProperty::class
            ) {
                // Select the native slot without reading overridden metadata
                // methods or field hooks on the custom reflector.
                [$owner, $name] = self::nativeProperty($property);
                $writer = $owner->getProperty($name);
            }
            $writer->setValue($entry, $value);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($property, previous: $e);
        }
    }

    private function __clone() {}

    private function conflictingStrategy(CreationStrategy $strategy): InvalidConfigurationException
    {
        return new InvalidConfigurationException(sprintf(
            'Creation strategies "%s" and "%s" cannot be combined for "%s".',
            $this->strategy->name,
            $strategy->name,
            $this->declaration->getName(),
        ));
    }

    private function propertyKey(ReflectionProperty $property): string
    {
        // Native setValue() can write a same-named public property on an unrelated object.
        [$declaringClass, $name] = self::nativeProperty($property);
        $owner = $declaringClass->getName();
        if (!is_a($this->entry ?? $this->declaration->getName(), $owner, true)) {
            throw new LogicException(sprintf('Property "%s::$%s" does not belong to this creation context.', $owner, $name));
        }
        return $owner . "\0" . $name;
    }

    /** @return array{ReflectionClass<object>,string} */
    private static function nativeProperty(ReflectionProperty $property): array
    {
        if ($property::class === ReflectionProperty::class) {
            return [$property->getDeclaringClass(), $property->getName()];
        }

        /** @var ReflectionClass<object> $declaringClass */
        $declaringClass = new ReflectionMethod(ReflectionProperty::class, 'getDeclaringClass')->invoke($property);
        /** @var string $name */
        $name = new ReflectionMethod(ReflectionProperty::class, 'getName')->invoke($property);
        return [$declaringClass, $name];
    }
}
