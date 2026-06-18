<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Psr\Log\LoggerInterface;

/**
 * Fixture class with various property shapes for Property resolver tests.
 * Properties are never populated by normal PHP flow; each test reflects on
 * the property it needs and exercises the resolver directly.
 */
final class TypedProperties
{
    public string $name;

    public int $count;

    #[Inject]
    public LoggerInterface $logger;

    #[Inject]
    public string $badInject; // non-class type - Inject must fail

    #[Init('Componenta\\DI\\Tests\\Fixture\\globalCallableFixture', [21])]
    public int $computed;

    #[Init([self::class, 'staticInit'])]
    public string $fromStatic;

    public string $plain;

    public static function staticInit(): string
    {
        return 'static-init-value';
    }
}
