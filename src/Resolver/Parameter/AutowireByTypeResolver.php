<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Internal\AvailableEntryLookup;
use Componenta\DI\Internal\Resolver\Parameter\ParameterContextAccess;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/** Autowires a named type or the sole available service in a simple union. */
final class AutowireByTypeResolver implements ParameterResolverInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return !$target->variadic && ($target->className !== null || $target->unionCandidates() !== []);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        if ($target->variadic) {
            return null;
        }

        $union = $target->className === null;

        try {
            if ($target->className !== null) {
                $typeName = $target->className;
                if (!AvailableEntryLookup::has($this->container, $typeName)) {
                    return null;
                }
            } else {
                $available = [];
                foreach ($target->unionCandidates() as $candidate) {
                    if (AvailableEntryLookup::has($this->container, $candidate)) {
                        $available[] = $candidate;
                    }
                }
                if ($available === []) {
                    return null;
                }
                if (count($available) > 1) {
                    throw ParameterContextAccess::failure(
                        $context,
                        $target->reflection,
                        reason: 'union autowiring is ambiguous; available services: ' . implode(', ', $available),
                    );
                }
                $typeName = $available[0];
            }

            $value = AvailableEntryLookup::get($this->container, $typeName);
            if ($union && (!$value instanceof $typeName || !$target->accepts($value))) {
                throw ParameterContextAccess::failure(
                    $context,
                    $target->reflection,
                    reason: sprintf(
                        'selected union service "%s" returned %s, which does not satisfy the selected type',
                        $typeName,
                        get_debug_type($value),
                    ),
                );
            }

            return [$target->position, $value];
        } catch (Throwable $e) {
            if ($e instanceof ContainerExceptionInterface) {
                throw $e;
            }

            throw ParameterContextAccess::failure(
                $context,
                $target->reflection,
                previous: $e,
            );
        }
    }

}
