<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;

test('late-loaded non-static delegator owners remain invalidatable container dependencies', function (): void {
    $short = 'LateDelegatorOwner_' . bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\' . $short;
    $container = (new ContainerBuilder())->build();

    $container->set('late.decorated', 'base');
    $container->delegator('late.decorated', $class . '::decorate');

    eval(sprintf(
        'namespace %s; final class %s { public function __construct(private string $suffix) {} public function decorate(string $entry): string { return $entry . $this->suffix; } }',
        __NAMESPACE__,
        $short,
    ));

    $container->set($class, new $class(':first'));
    expect($container->get('late.decorated'))->toBe('base:first');

    $container->set($class, new $class(':second'));

    expect($container->get('late.decorated'))->toBe('base:second');
});
