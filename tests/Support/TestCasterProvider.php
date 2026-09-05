<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;

final readonly class TestCasterProvider implements CasterProviderInterface
{
    public function provide(string $name): ?CasterInterface
    {
        return match ($name) {
            'trim' => new TrimCaster(),
            'int' => new IntCaster(),
            default => null,
        };
    }
}

final class TrimCaster implements CasterInterface
{
    public string $name { get => 'trim'; }

    public function cast(mixed $value): mixed
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException('The trim caster expects a scalar or stringable value.');
        }

        return trim((string) $value);
    }
}

final class IntCaster implements CasterInterface
{
    public string $name { get => 'int'; }

    public function cast(mixed $value): mixed
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('The int caster expects a scalar value.');
        }

        return (int) $value;
    }
}
