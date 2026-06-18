<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\Config\DefaultValue;

/**
 * Marks a property or parameter for type casting.
 *
 * @example Property usage
 * ```php
 * class UserDTO {
 *     #[Cast('int')]
 *     public int $age;
 *
 *     #[Cast('datetime', default: 'now')]
 *     public \DateTimeInterface $createdAt;
 * }
 * ```
 *
 * @example Constructor parameter usage
 * ```php
 * class UserDTO {
 *     public function __construct(
 *         #[Cast('int')]
 *         public int $age,
 *
 *         #[Cast('datetime', default: 'now')]
 *         public \DateTimeInterface $createdAt,
 *     ) {}
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
readonly class Cast
{
    public function __construct(
        public string $name,
        public mixed $default = DefaultValue::None,
    ) {}
}