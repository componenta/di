<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Reflection\Reflection;
use ReflectionClass;

/**
 * Executes {@see SetUp} methods on a fully-constructed instance.
 *
 * Responsibilities:
 *  - collect all `#[SetUp]` attributes in declaration order
 *  - unwrap value-object params via a pluggable chain of
 *    {@see SetUpValueUnwrapperInterface} instances (Open/Closed for new types)
 *  - merge them into the resolution context and invoke the method through
 *    {@see ParametersResolver} so the whole parameter pipeline applies.
 *
 * The unwrapper chain is externally configured: adding support for a new
 * value-object type means registering one more unwrapper - no changes to this
 * class are required.
 */
final class SetUpRunner implements PostInitializerInterface
{
    /** @var list<SetUpValueUnwrapperInterface> */
    private array $valueUnwrappers;

    public function __construct(
        private readonly ParametersResolver $parametersResolver,
        SetUpValueUnwrapperInterface ...$valueUnwrappers,
    ) {
        $this->valueUnwrappers = array_values($valueUnwrappers);
    }

    public function addValueUnwrapper(SetUpValueUnwrapperInterface $unwrapper): void
    {
        $this->valueUnwrappers[] = $unwrapper;
    }

    /**
     * @param array<string, mixed> $context Context forwarded to parameter resolvers.
     */
    public function run(ReflectionClass $reflector, object $entry, array $context = []): void
    {
        $attributes = Reflection::getMetadata($reflector, SetUp::class) ?? [];

        foreach ($attributes as $attribute) {
            $methodReflector = Reflection::callable([$entry, $attribute->method]);
            $resolvedParams  = $this->unwrapParams($attribute->params);

            $methodParams = $this->parametersResolver->resolve(
                $methodReflector->getParameters(),
                array_merge($context, $resolvedParams),
            );

            $entry->{$attribute->method}(...$methodParams);
        }
    }

    /**
     * Applies the unwrapper chain to every value in SetUp::$params.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function unwrapParams(array $params): array
    {
        if ($this->valueUnwrappers === []) {
            return $params;
        }

        $resolved = [];

        foreach ($params as $key => $value) {
            $resolved[$key] = $this->unwrap($value, (string) $key);
        }

        return $resolved;
    }

    private function unwrap(mixed $value, string $key): mixed
    {
        foreach ($this->valueUnwrappers as $unwrapper) {
            if ($unwrapper->supports($value)) {
                return $unwrapper->unwrap($value, $key);
            }
        }

        return $value;
    }
}
