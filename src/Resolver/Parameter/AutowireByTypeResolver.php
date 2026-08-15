<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/** Autowires a parameter by its declared class/interface type. */
final class AutowireByTypeResolver implements ParameterResolverInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->className !== null;
    }

    /** @return array{0: int, 1: mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->resolveType(
            $target->className,
            $target,
            $context,
        );
    }

    /**
     * @param class-string|null $typeName
     * @return array{0: int, 1: mixed}|null
     */
    private function resolveType(
        ?string $typeName,
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        if ($typeName === null) {
            return null;
        }

        try {
            if (!$this->container->has($typeName)) {
                return null;
            }

            return [$target->position, $this->container->get($typeName)];
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
}
