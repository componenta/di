<?php

declare(strict_types=1);

use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerBuilder;

interface MappedSentinelFactoryContract {}

final readonly class MappedSentinelFactoryResult implements MappedSentinelFactoryContract
{
    /** @param array<string|int, mixed> $context */
    public function __construct(public array $context) {}
}

it('preserves caller-owned PHP_INT_MIN context values for ordinary factories', function (): void {
    $container = (new ContainerBuilder())
        ->addFactory(
            MappedSentinelFactoryContract::class,
            static fn(ContainerValue $container, array $context): MappedSentinelFactoryContract =>
                new MappedSentinelFactoryResult($context),
        )
        ->build();
    $context = [
        PHP_INT_MIN => 'caller-owned-sentinel-position',
        'value' => 'programmatic-value',
    ];

    $result = $container->make(MappedSentinelFactoryContract::class, $context);

    expect($result)->toBeInstanceOf(MappedSentinelFactoryResult::class)
        ->and($result->context)->toBe($context);
});
