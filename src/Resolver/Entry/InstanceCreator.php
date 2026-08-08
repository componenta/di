<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Resolver\Parameter\ParametersResolver;
use ReflectionClass;

/**
 * Creates or initializes an object by invoking its constructor with parameters
 * resolved through ParametersResolver.
 *
 * Constructor suppression is no longer inferred here from #[NoConstructor].
 * That lifecycle decision belongs to the before-instantiation attribute
 * pipeline and is applied by ReflectionResolver.
 */
final readonly class InstanceCreator
{
    public function __construct(
        private ParametersResolver $parametersResolver,
    ) {}

    /**
     * @param array<string|int, mixed> $context
     */
    public function create(ReflectionClass $reflector, array $context = []): object
    {
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return $reflector->newInstance();
        }

        $params = $this->parametersResolver->resolve($constructor->getParameters(), $context);

        return $reflector->newInstanceArgs($params);
    }

    /**
     * Calls the constructor on an already-allocated lazy ghost.
     *
     * @param array<string|int, mixed> $context
     */
    public function initialize(object $entry, ReflectionClass $reflector, array $context = []): void
    {
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return;
        }

        $params = $this->parametersResolver->resolve($constructor->getParameters(), $context);
        $entry->__construct(...$params);
    }
}
