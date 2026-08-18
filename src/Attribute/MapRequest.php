<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;

/** Maps one or more PSR-7 request sources into an array or class-typed DTO. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapRequest
{
    /**
     * @param list<RequestDataSource> $sources
     * @param array<string, string> $map source key => target key; prefix source with ? for optional
     * @param list<string> $exclude
     */
    public function __construct(
        public array $sources = [RequestDataSource::Payload],
        public array $map = [],
        public array $exclude = [],
        public RequestDataConflictPolicy $conflictPolicy = RequestDataConflictPolicy::Reject,
    ) {}
}
