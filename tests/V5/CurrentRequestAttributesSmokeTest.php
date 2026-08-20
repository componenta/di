<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use ReflectionClass;

it('declares current request context attributes for parameters only', function (): void {
    foreach ([CurrentRequest::class, CurrentUri::class] as $attribute) {
        $metadata = (new ReflectionClass($attribute))->getAttributes(Attribute::class)[0]->newInstance();

        expect($metadata->flags)->toBe(Attribute::TARGET_PARAMETER);
    }
});
