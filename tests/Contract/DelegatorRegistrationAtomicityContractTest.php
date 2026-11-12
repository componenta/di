<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Exception\InvalidConfigurationException;
use PHPUnit\Framework\Assert;
use RuntimeException;

use function Componenta\DI\Tests\Support\container;

final class DelegatorAutoloadFailure extends RuntimeException {}

test('failed delegator registration leaves no partial pipeline entry', function (): void {
    $container = container(['services' => ['decorated' => 'base']]);
    $missing = __NAMESPACE__ . '\\RejectedAutoloadDecorator';
    $failure = new DelegatorAutoloadFailure('Decorator autoload failed.');
    $autoload = static function (string $class) use ($missing, $failure): void {
        if ($class === $missing) {
            throw $failure;
        }
    };
    spl_autoload_register($autoload, true, true);
    try {
        try {
            $container->delegator('decorated', $missing . '::decorate');
            Assert::fail('The failed autoloader must abort delegator registration.');
        } catch (InvalidConfigurationException $exception) {
            expect($exception->getPrevious())->toBe($failure)
                ->and($exception->getPrevious()?->getMessage())->toBe('Decorator autoload failed.');
        }
    } finally {
        spl_autoload_unregister($autoload);
    }

    $container->delegator('decorated', static fn(string $entry): string => $entry . ':accepted');

    expect($container->get('decorated'))->toBe('base:accepted');
});
