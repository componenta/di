<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Marks a class to be instantiated without calling the constructor.
 *
 * Uses ReflectionClass::newInstanceWithoutConstructor() to create
 * a raw instance. The normal reflection pipeline still runs property
 * injection and SetUp methods after allocation when they are configured.
 * Promoted properties with property-injection attributes are initialized by
 * those handlers because the constructor does not assign their values.
 *
 * Useful for legacy classes or special instantiation requirements.
 *
 * @example
 * ```php
 * #[NoConstructor, SetUp('initialize')]
 * class LegacyService {
 *     #[Inject]
 *     private DatabaseConnection $db;
 *
 *     public function initialize(): void {
 *         // Manual initialization
 *     }
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
readonly class NoConstructor {}
