<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use ReflectionParameter;
use Throwable;

/** Read-only capability backed by the container's own parameter pipeline. @internal */
final readonly class ParameterResolutionService implements ParametersResolverInterface
{
    public function __construct(private ParametersResolver $resolver) {}

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int,mixed> $providedParameters
     * @return array<array-key,mixed>
     */
    public function resolve(array $parameters, array $providedParameters = []): array
    {
        if (!$this->resolver->isSealed) {
            throw new InvalidConfigurationException('The parameter resolution service requires a sealed resolver pipeline.');
        }

        try {
            return $this->resolver->resolve($parameters, $providedParameters);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ResolutionException('Failed to resolve invocation arguments.', previous: $e);
        }
    }
}
