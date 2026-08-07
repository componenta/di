<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

/** PHP fragment produced for one resolver slot and one parameter target. */
final readonly class GeneratedResolverCode
{
    private function __construct(
        public GeneratedResolverCodeType $type,
        public string $code = '',
        public bool $usesTarget = false,
        public EmptyContextResolution $emptyContext = EmptyContextResolution::Unknown,
    ) {}

    public static function skip(): self
    {
        return new self(
            GeneratedResolverCodeType::Skip,
            emptyContext: EmptyContextResolution::Skip,
        );
    }

    public static function conditional(
        string $code,
        bool $usesTarget = false,
        EmptyContextResolution $emptyContext = EmptyContextResolution::Unknown,
    ): self {
        return new self(
            GeneratedResolverCodeType::Conditional,
            $code,
            $usesTarget,
            $emptyContext,
        );
    }

    public static function terminal(
        string $code,
        bool $usesTarget = false,
        EmptyContextResolution $emptyContext = EmptyContextResolution::Unknown,
    ): self {
        return new self(
            GeneratedResolverCodeType::Terminal,
            $code,
            $usesTarget,
            $emptyContext,
        );
    }
}
