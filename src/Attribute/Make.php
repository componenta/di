<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;

/** Creates a fresh value through FactoryInterface. */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Make implements ParameterSourceAttributeInterface
{
    /** @param array<string|int, mixed> $params */
    public function __construct(
        public ?string $entry = null,
        public array $params = [],
    ) {}
}
