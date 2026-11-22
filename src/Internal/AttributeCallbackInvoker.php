<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionFunction;

/**
 * Binds one semantic argument while resolving only the callback's DI suffix.
 *
 * @internal
 */
final readonly class AttributeCallbackInvoker
{
    public function __construct(
        private ParametersResolver $parameters,
        private CallableInvokerInterface $invoker,
    ) {}

    /**
     * @param array<string|int,mixed> $providedParameters
     * @param class-string|null $diagnosticOwner
     * @param (Closure():void)|null $beforeInvocation
     */
    public function invoke(
        Closure $callback,
        mixed $argument,
        array $providedParameters = [],
        ?string $diagnosticOwner = null,
        ?Closure $beforeInvocation = null,
    ): mixed {
        $declarations = new ReflectionFunction($callback)->getParameters();
        if ($declarations === []) {
            $beforeInvocation?->__invoke();
            return $this->invoker->call(new PreparedCallable($callback));
        }

        $first = $this->parameters->target($declarations[0]);
        if ($first->byReference || $first->variadic) {
            throw new InvalidConfigurationException(sprintf(
                'The semantic callback parameter "$%s" must not be passed by reference or variadic.',
                $first->name,
            ));
        }
        if (!$first->accepts($argument)) {
            throw ResolutionException::forParameter(
                $first->reflection,
                reason: 'the semantic callback argument does not satisfy the declared type',
                resolvedParameters: [0 => $argument],
            );
        }

        $arguments = $this->parameters->resolvePreparedSuffix(
            $this->parameters->prepare(array_slice($declarations, 1), $callback),
            $first,
            $argument,
            $providedParameters,
            $diagnosticOwner,
            $beforeInvocation,
        );

        $beforeInvocation?->__invoke();
        return $this->invoker->call(new PreparedCallable($callback), $arguments);
    }
}
