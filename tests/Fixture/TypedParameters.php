<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Psr\Log\LoggerInterface;

/**
 * Pure fixture used to obtain ReflectionParameter instances for resolver tests.
 * Methods are never called; they exist only as reflection targets.
 */
final class TypedParameters
{
    public function untyped($a, $b): void {}

    public function typedString(string $name, ?string $maybe): void {}

    public function primitives(int $count, string $text, bool $flag): void {}

    public function withDefaults(int $page = 1, string $sort = 'asc'): void {}

    public function withNullable(?string $name, ?LoggerInterface $logger): void {}

    public function byType(LoggerInterface $logger): void {}

    public function byUnion(LoggerInterface|\stdClass $dep): void {}

    public function noTypeNoDefault($anything): void {}
}
