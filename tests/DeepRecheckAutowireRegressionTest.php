<?php

declare(strict_types=1);

use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Tests\Fixture\PrivateInjectedChild;
use Componenta\DI\Tests\Fixture\PrivateInjectedDependency;

test('autowire compilation graph includes dependencies injected into private parent properties', function () {
    $classes = (new AutowireClassGraph())->expand([PrivateInjectedChild::class]);

    expect($classes)
        ->toContain(PrivateInjectedChild::class)
        ->toContain(PrivateInjectedDependency::class);
});
