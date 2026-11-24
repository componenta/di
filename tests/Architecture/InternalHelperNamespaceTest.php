<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Architecture\InternalHelperNamespace;

test('implementation helper functions stay out of the public DI namespace', function (): void {
    expect(function_exists('Componenta\\DI\\normalize_env_name'))->toBeFalse()
        ->and(function_exists('Componenta\\DI\\is_entry_class_eligible'))->toBeFalse()
        ->and(function_exists('Componenta\\DI\\validate_parameter_resolution_result'))->toBeFalse()
        ->and(function_exists('Componenta\\DI\\transform_request_mapper_data'))->toBeFalse();
});
