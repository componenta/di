<?php

declare(strict_types=1);

use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry;

it('does not expose removed fingerprint-era registry metadata', function (): void {
    expect(property_exists(ParameterResolverCodeGeneratorRegistry::class, 'version'))->toBeFalse()
        ->and(property_exists(ParameterResolverCodeGeneratorRegistry::class, 'generatorList'))->toBeFalse();
});
