<?php

declare(strict_types=1);

use function Componenta\DI\is_entry_class_eligible;
use function Componenta\DI\normalize_env_name;

it('does not expose the removed v5 migration subsystems', function (): void {
    expect(class_exists('Componenta\\DI\\ResolutionContext'))->toBeFalse()
        ->and(interface_exists('Componenta\\DI\\Value\\ValueFallbackInterface'))->toBeFalse()
        ->and(class_exists('Componenta\\DI\\Value\\ValuePipeline'))->toBeFalse()
        ->and(interface_exists('Componenta\\DI\\Attribute\\Handler\\ValueProviderHandlerInterface'))->toBeFalse();
});

it('autoloads package helper functions instead of one-method helper classes', function (): void {
    expect(function_exists('Componenta\\DI\\normalize_env_name'))->toBeTrue()
        ->and(function_exists('Componenta\\DI\\is_entry_class_eligible'))->toBeTrue()
        ->and(function_exists('Componenta\\DI\\with_suppressed_warnings'))->toBeTrue()
        ->and(function_exists('Componenta\\DI\\validate_parameter_resolution_result'))->toBeTrue()
        ->and(function_exists('Componenta\\DI\\compiled_factory_pipeline_fingerprint'))->toBeTrue()
        ->and(normalize_env_name('someValue'))->toBe('SOME_VALUE')
        ->and(is_entry_class_eligible(new \ReflectionClass(\stdClass::class)))->toBeTrue();
});

it('does not expose obsolete helper classes or NullContainer', function (): void {
    foreach ([
        'Componenta\\DI\\NullContainer',
        'Componenta\\DI\\Resolver\\EnvNameNormalizer',
        'Componenta\\DI\\Resolver\\Entry\\EntryClassEligibility',
        'Componenta\\DI\\Internal\\WarningGuard',
        'Componenta\\DI\\Resolver\\Parameter\\ParameterResolutionResult',
        'Componenta\\DI\\Compile\\Definition\\DefaultDefinitionCodeGenerators',
        'Componenta\\DI\\Compile\\Factory\\CompiledFactoryPipelineFingerprint',
    ] as $class) {
        expect(class_exists($class) || interface_exists($class))->toBeFalse();
    }
});
