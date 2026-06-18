<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Marks a class to be instantiated without calling the constructor.
 *
 * Uses ReflectionClass::newInstanceWithoutConstructor() to create
 * a raw instance. The normal reflection pipeline still runs property
 * injection and SetUp methods after allocation when they are configured.
 *
 * Useful for legacy classes or special instantiation requirements.
 *
 * @example
 * ```php
 * #[NoConstructor]
 * class LegacyService {
 *     #[Inject]
 *     private DatabaseConnection $db;
 *
 *     #[SetUp('initialize')]
 *     public function initialize(): void {
 *         // Manual initialization
 *     }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
readonly class NoConstructor
{
}
