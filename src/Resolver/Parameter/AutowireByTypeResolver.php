<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

/** Autowires a parameter by its declared class/interface type. */
final class AutowireByTypeResolver implements ParameterResolverInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->className !== null;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $typeName = $target->className;
        if ($typeName === null) {
            return null;
        }

        try {
            return [$target->position, $this->container->get($typeName)];
        } catch (NotFoundExceptionInterface) {
            return null;
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
