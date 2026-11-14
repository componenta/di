<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/** Autowires a named type or the sole available service in a simple union. */
final class AutowireByTypeResolver implements ParameterResolverInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return !$target->variadic && ($target->className !== null || self::unionCandidates($target) !== []);
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
                if (!$this->container->has($typeName)) {
                    return null;
                }
            } else {
                $available = [];
                foreach (self::unionCandidates($target) as $candidate) {
                    if ($this->container->has($candidate)) {
                        $available[] = $candidate;
                    }
                }
                if ($available === []) {
                    return null;
                }
                if (count($available) > 1) {
                    throw ResolutionException::forParameter(
                        $target->reflection,
                        reason: 'union autowiring is ambiguous; available services: ' . implode(', ', $available),
                        providedParameters: $context->provided,
                        resolvedParameters: $context->resolved,
                    );
                }
                $typeName = $available[0];
            }

            $value = $this->container->get($typeName);
            if ($union && (!$value instanceof $typeName || !$target->accepts($value))) {
                throw ResolutionException::forParameter(
                    $target->reflection,
                    reason: sprintf(
                        'selected union service "%s" returned %s, which does not satisfy the selected type',
                        $typeName,
                        get_debug_type($value),
                    ),
                    providedParameters: $context->provided,
                    resolvedParameters: $context->resolved,
                );
            }

            return [$target->position, $value];
        } catch (Throwable $e) {
            if ($e instanceof ContainerExceptionInterface) {
                throw $e;
            }

            throw ResolutionException::forParameter(
                $target->reflection,
                previous: $e,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }
    }

    /** @return list<class-string> */
    private static function unionCandidates(ParameterTarget $target): array
    {
        return TypeHints::simpleUnionClassNames($target->type, $target->reflection->getDeclaringClass());
    }
}
