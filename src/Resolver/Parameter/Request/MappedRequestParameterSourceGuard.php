<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\TypeHints;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use ReflectionClass;
use ReflectionParameter;

/** Guards mapped HTTP DTO data from shadowing explicitly declared parameter sources. */
final class MappedRequestParameterSourceGuard
{
    /** @var array<class-string, array<string, class-string>> */
    private static array $sourceCache = [];

    /**
     * @param class-string $dtoClass
     * @param array<string|int, mixed> $data
     */
    public static function assertNoConflicts(string $dtoClass, array $data): void
    {
        if ($data === []) {
            return;
        }

        foreach (self::sources($dtoClass) as $parameter => $source) {
            if (!array_key_exists($parameter, $data)) {
                continue;
            }

            throw new RequestParameterSourceConflictException(
                dtoClass: $dtoClass,
                key: $parameter,
                source: $source,
            );
        }
    }

    /**
     * @param class-string $dtoClass
     * @return array<string, class-string>
     */
    private static function sources(string $dtoClass): array
    {
        if (array_key_exists($dtoClass, self::$sourceCache)) {
            return self::$sourceCache[$dtoClass];
        }

        $constructor = (new ReflectionClass($dtoClass))->getConstructor();
        if ($constructor === null) {
            return self::$sourceCache[$dtoClass] = [];
        }

        $sources = [];

        foreach ($constructor->getParameters() as $parameter) {
            $source = self::declaredSource($parameter);
            if ($source !== null) {
                $sources[$parameter->getName()] = $source;
            }
        }

        return self::$sourceCache[$dtoClass] = $sources;
    }

    /** @return class-string|null */
    private static function declaredSource(ReflectionParameter $parameter): ?string
    {
        foreach ($parameter->getAttributes() as $attribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $attribute->getName();

            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }

        foreach (TypeHints::classNames(
            $parameter->getType(),
            $parameter->getDeclaringClass(),
        ) as $typeName) {
            if (is_a($typeName, ServerRequestInterface::class, true)
                || is_a($typeName, UriInterface::class, true)
            ) {
                return $typeName;
            }
        }

        return null;
    }
}
