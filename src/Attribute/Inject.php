<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Marks a property for injection from DI container by its type.
 *
 * The property type is used as the service ID to resolve from container.
 * Constructor and callable parameters are autowired by type automatically, so
 * this attribute is only needed on properties.
 *
 * @example Property injection by type
 * ```php
 * class OrderService {
 *     #[Inject]
 *     private LoggerInterface $logger;
 *
 *     #[Inject]
 *     private EventDispatcherInterface $dispatcher;
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
readonly class Inject
{
}
