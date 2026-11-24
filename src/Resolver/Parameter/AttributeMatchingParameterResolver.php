<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

use Closure;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Target\ParameterTarget;

/** Resolves parameters whose declared attributes match a given name or type. */
final readonly class AttributeMatchingParameterResolver implements ParameterResolverInterface
{
    /** @param Closure(ParameterTarget, ParameterResolutionContext): (array{0: int, 1: mixed}|null) $resolve */
    public function __construct(public string $attribute, private Closure $resolve)
    {
        if ($attribute === '') {
            throw new InvalidConfigurationException('A parameter resolver attribute name must not be empty.');
        }
    }

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute($this->attribute);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        return ($this->resolve)($target, $context);
    }
}
