<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Architecture\InternalHelperNamespace;

test('implementation helper functions stay out of the public DI namespace', function (): void {
    $publicFunctions = array_filter(get_defined_functions()['user'], static function (string $function): bool {
        $separator = strrpos($function, '\\');
        return $separator !== false && strtolower(substr($function, 0, $separator)) === 'componenta\\di';
    });

    expect(array_values($publicFunctions))->toBe([]);
});
