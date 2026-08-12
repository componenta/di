<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

/** Immutable classification of classes discovered for AOT compilation. */
final readonly class AutowireCompilationPlan
{
    /**
     * @param list<class-string> $invokables Classes eligible for the invokable fast path.
     * @param list<class-string> $factories Classes that require generated factories.
     */
    public function __construct(
        public array $invokables,
        public array $factories,
    ) {}
}
