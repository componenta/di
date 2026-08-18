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

final readonly class TrimCaster implements CasterInterface
{
    public string $name { get => 'trim'; }

    public function cast(mixed $value): mixed
    {
        return trim((string) $value);
    }
}

final readonly class IntCaster implements CasterInterface
{
    public string $name { get => 'int'; }

    public function cast(mixed $value): mixed
    {
        return (int) $value;
    }
}
