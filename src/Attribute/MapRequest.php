<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;

/**
 * Maps one or more PSR-7 request sources into an array or class-typed DTO.
 *
 * The attribute is declarative only. Extraction, validation and transformation
 * are owned by the request value-provider pipeline.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapRequest
{
    /** Wildcard for selecting all request attributes or uploaded files. */
    public const string ALL = '*';

    /**
     * @param list<RequestDataSource> $sources
     * @param array<string, string> $map source key => target key; prefix source with ? for optional
     * @param list<string> $exclude
     * @param array<string, mixed> $defaults target key => final default value
     * @param array<string, string> $cast target key => caster name
     * @param array<string, array<string, mixed>> $sortMap sort alias => orderBy value
     * @param list<string> $attributes request-attribute names, or [self::ALL]
     * @param list<string> $files uploaded-file names, or [self::ALL]
     */
    public function __construct(
        public array $sources = [RequestDataSource::Payload],
        public array $map = [],
        public array $exclude = [],
        public RequestDataConflictPolicy $conflictPolicy = RequestDataConflictPolicy::Reject,
        public array $defaults = [],
        public array $cast = [],
        public array $sortMap = [],
        public array $attributes = [],
        public array $files = [],
    ) {}
}
