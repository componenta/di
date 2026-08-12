<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Autowire;

use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\InvokableSpecificationValidator;
use ReflectionClass;

/** Plans AOT work without mutating ContainerBuilder state. */
final readonly class AutowireCompilationPlanner
{
    /** @param array<string, non-empty-string> $aliases */
    public function __construct(
        private AttributeProcessor $attributes,
        private array $aliases = [],
    ) {}

    /**
     * @param iterable<AutowireEntry|class-string> $entries
     * @param array<string, true> $excluded
     */
    public function plan(iterable $entries, array $excluded = []): AutowireCompilationPlan
    {
        $classes = (new AutowireClassGraph($this->aliases))->expand($entries, $excluded);
        $invokables = [];
        $factories = [];

        foreach ($classes as $class) {
            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if (($constructor === null || $constructor->getNumberOfParameters() === 0)
                && InvokableSpecificationValidator::supportsAttributePipeline(
                    $reflection,
                    $this->attributes,
                )
            ) {
                $invokables[] = $class;
                continue;
            }

            $factories[] = $class;
        }

        return new AutowireCompilationPlan($invokables, $factories);
    }
}
