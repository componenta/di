<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute;

use InvalidArgumentException;
use LogicException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Mutable registration-time collection of attribute handlers.
 *
 * Handler phase and priority are part of immutable registration metadata.
 * Mutating either value after registration would invalidate cached class maps
 * without changing the registry revision, so such handlers are rejected.
 */
final class AttributeHandlerRegistry
{
    /** @var array<class-string, true> */
    private static array $orderingMetadataValidated = [];

    /**
     * @var list<array{
     *     handler: AttributeHandlerInterface,
     *     order: int,
     *     phase: AttributePhase,
     *     priority: int
     * }>
     */
    private array $items = [];
    /** @var array<int, true> */
    private array $registered = [];


    /** @var list<array{handler: AttributeHandlerInterface, order: int, phase: AttributePhase, priority: int}>|null */
    private ?array $orderedItems = null;

    /** @var list<AttributeHandlerInterface>|null */
    private ?array $orderedHandlers = null;

    private int $revision = 0;

    private bool $sealed = false;

    public int $version {
        get {
            if (!$this->sealed) {
                $this->assertStable();
            }

            return $this->revision;
        }
    }

    /**
     * Ordered defensive list. Higher priority comes first and equal priorities
     * preserve registration order.
     *
     * @var list<AttributeHandlerInterface>
     */
    public array $handlers {
        get {
            if (!$this->sealed) {
                $this->assertStable();
            }

            return $this->orderedHandlers();
        }
    }
    /**
     * Ordered immutable registration metadata used by the dispatch compiler.
     *
     * @return list<array{handler: AttributeHandlerInterface, order: int, phase: AttributePhase, priority: int}>
     */
    public function registrations(): array
    {
        if (!$this->sealed) {
            $this->assertStable();
        }

        return $this->orderedItems();
    }

    public function add(AttributeHandlerInterface $handler): void
    {
        if ($this->sealed) {
            throw new LogicException(
                'Attribute handler registry is sealed and cannot be changed.',
            );
        }

        $this->assertImmutableOrderingMetadata($handler);

        $objectId = spl_object_id($handler);
        if (isset($this->registered[$objectId])) {
            throw new InvalidArgumentException(sprintf(
                'Attribute handler %s is already registered.',
                $handler::class,
            ));
        }

        $this->items[] = [
            'handler' => $handler,
            'order' => count($this->items),
            'phase' => $handler->phase,
            'priority' => $handler->priority,
        ];
        $this->registered[$objectId] = true;
        $this->orderedItems = null;
        $this->orderedHandlers = null;
        ++$this->revision;
    }

    /** Prevents runtime drift after the container composition is complete. */
    public function seal(): void
    {
        $this->assertStable();
        $this->registered = [];
        $this->sealed = true;
    }

    /**
     * @param iterable<AttributeHandlerInterface> $handlers
     */
    public function addAll(iterable $handlers): void
    {
        foreach ($handlers as $handler) {
            $this->add($handler);
        }
    }

    /** @return list<AttributeHandlerInterface> */
    private function orderedHandlers(): array
    {
        if ($this->orderedHandlers !== null) {
            return $this->orderedHandlers;
        }

        return $this->orderedHandlers = array_column($this->orderedItems(), 'handler');
    }

    /** @return list<array{handler: AttributeHandlerInterface, order: int, phase: AttributePhase, priority: int}> */
    private function orderedItems(): array
    {
        if ($this->orderedItems !== null) {
            return $this->orderedItems;
        }

        $items = $this->items;

        usort(
            $items,
            static fn(array $left, array $right): int =>
                $right['priority'] <=> $left['priority']
                ?: $left['order'] <=> $right['order'],
        );

        return $this->orderedItems = $items;
    }

    /**
     * Phase and priority determine both runtime and generated execution order.
     * Accept only genuinely immutable public contracts: a get-only property
     * hook or a readonly property initialized by the handler constructor.
     */
    private function assertImmutableOrderingMetadata(AttributeHandlerInterface $handler): void
    {
        $class = $handler::class;
        if (isset(self::$orderingMetadataValidated[$class])) {
            return;
        }

        foreach (['phase', 'priority'] as $name) {
            $property = new ReflectionProperty($handler, $name);
            $settableType = $property->getSettableType();
            $getOnly = $settableType instanceof ReflectionNamedType
                && $settableType->getName() === 'never';

            if (!$getOnly && !$property->isReadOnly()) {
                throw new InvalidArgumentException(sprintf(
                    'Attribute handler %s exposes mutable public $%s metadata; '
                    . 'phase and priority must be get-only or readonly.',
                    $handler::class,
                    $name,
                ));
            }
        }

        self::$orderingMetadataValidated[$class] = true;
    }

    private function assertStable(): void
    {
        foreach ($this->items as $item) {
            $handler = $item['handler'];

            if ($handler->phase !== $item['phase']
                || $handler->priority !== $item['priority']
            ) {
                throw new LogicException(sprintf(
                    'Attribute handler %s changed phase or priority after registration; '
                    . 'handler ordering metadata must remain immutable.',
                    $handler::class,
                ));
            }
        }
    }
}
