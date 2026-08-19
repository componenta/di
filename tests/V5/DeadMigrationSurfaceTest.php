<?php

declare(strict_types=1);

it('does not expose the removed v5 migration subsystems', function (): void {
    expect(class_exists('Componenta\\DI\\ResolutionContext'))->toBeFalse()
        ->and(interface_exists('Componenta\\DI\\Value\\ValueFallbackInterface'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Value\\ValuePipeline'))->toBeFalse()
        ->and(interface_exists('Componenta\\DI\\Attribute\\Handler\\ValueProviderHandlerInterface'))->toBeFalse();
});
