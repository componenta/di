<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

/**
 * Class with a mix of property shapes to exercise PropertyInjector's
 * skip rules (static / promoted / readonly-initialized).
 */
final class InjectableTargets
{
    public static string $staticProp = 'static';

    public string $writable = 'initial';

    public readonly string $readonlyInitialized;

    public function __construct(public string $promoted = 'from-ctor')
    {
        $this->readonlyInitialized = 'ctor-set';
    }
}
