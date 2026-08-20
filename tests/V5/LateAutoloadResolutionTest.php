<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;

test('a reflection miss does not hide a class provided by a later autoloader', function (): void {
    $short = 'AuditLateLoaded_' . bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\' . $short;
    $container = (new ContainerBuilder())->build();

    expect($container->has($class))->toBeFalse();

    $loader = static function (string $requested) use ($class, $short): void {
        if ($requested !== $class) {
            return;
        }

        eval(sprintf('namespace %s; final class %s {}', __NAMESPACE__, $short));
    };
    spl_autoload_register($loader);

    try {
        expect($container->has($class))->toBeTrue()
            ->and($container->make($class))->toBeInstanceOf($class);
    } finally {
        spl_autoload_unregister($loader);
    }
});
