<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Initializes a property value by executing a callable.
 *
 * Unlike #[Cast] which transforms existing context values, #[Init] executes
 * a callable once during object initialization. Useful for timestamps, UUIDs,
 * computed defaults, etc.
 *
 * Native PHP attribute arguments must be constant expressions, so normal
 * attribute usage supports:
 * - String: 'Class::method', 'function_name', or an invokable service id
 * - Array: [Class::class, 'method']
 *
 * When an Init instance is constructed programmatically rather than through
 * attribute syntax, any PHP callable accepted by CallableResolver may be used,
 * including closures and [object, method].
 *
 * @example Static method
 * ```php
 * class EventDTO {
 *     #[Init([Uuid::class, 'uuid4'])]
 *     public UuidInterface $id;
 *
 *     #[Init([Carbon::class, 'now'])]
 *     public Carbon $timestamp;
 * }
 * ```
 *
 * @example Invokable service
 * ```php
 * class AuditDTO {
 *     #[Init(CurrentUserIdGenerator::class)]
 *     public int $userId;
 *
 *     #[Init(SequenceGenerator::class)]
 *     public int $sequence;
 * }
 * ```
 *
 * @example With parameters
 * ```php
 * class ConfigDTO {
 *     #[Init('date', ['Y-m-d'])]
 *     public string $today;
 *
 *     #[Init([Generator::class, 'generate'], ['prefix' => 'ID'])]
 *     public string $id;
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Init
{
    /**
     * @param mixed $callable Callable to execute for value initialization.
     * @param array $params Parameters to pass to the callable.
     */
    public function __construct(
        public mixed $callable,
        public array $params = [],
    ) {}
}
