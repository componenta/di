<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Init;
use Componenta\DI\ContainerBuilder;

final class PromotedInitRegressionTarget
{
    public function __construct(
        #[Init('strtoupper', ['initialized'])]
        public string $value = 'constructor',
    ) {}
}

test('Init can overwrite a mutable promoted property after construction', function () {
    $entry = (new ContainerBuilder())->build()->make(PromotedInitRegressionTarget::class);

    expect($entry->value)->toBe('INITIALIZED');
});
