<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Init;
use Componenta\DI\ContainerBuilder;

final readonly class PromotedReadonlyInitRegressionTarget
{
    public function __construct(
        #[Init('strtoupper', ['ignored'])]
        public string $value = 'constructor',
    ) {}
}

test('Init does not overwrite an initialized readonly promoted property', function () {
    $entry = (new ContainerBuilder())->build()->make(PromotedReadonlyInitRegressionTarget::class);

    expect($entry->value)->toBe('constructor');
});
