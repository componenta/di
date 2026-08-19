<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Http\Message\UriInterface;

/** Resolves non-attribute request context such as UriInterface. */
final readonly class RequestContextResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return in_array(UriInterface::class, $target->typeNames, true);
    }

    /** @return array{0:int,1:mixed}|null */
    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        if (!in_array(UriInterface::class, $target->typeNames, true)) {
            return null;
        }

        $request = RequestParameter::get($context->provided);
        if ($request === null) {
            throw ResolutionException::forParameter(
                $target->reflection,
                reason: sprintf('PSR-7 request is required to resolve type "%s"', UriInterface::class),
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }

        return [$target->position, $request->getUri()];
    }
}
