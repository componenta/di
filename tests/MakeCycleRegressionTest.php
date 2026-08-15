<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Make;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\CircularDependencyException;

final class MakeCycleRegressionA
{
    public function __construct(
        #[Make]
        public MakeCycleRegressionB $dependency,
    ) {}
}

final class MakeCycleRegressionB
{
    public function __construct(
        #[Make]
        public MakeCycleRegressionA $dependency,
    ) {}
}

test('make detects cycles created through Make attributes', function () {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(MakeCycleRegressionA::class))
        ->toThrow(CircularDependencyException::class);
});
