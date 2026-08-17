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
    /**
     * @var array<class-string, list<array{
     *     parameter: string,
     *     source: class-string,
     *     keys: list<string>
     * }>>
     */
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

        foreach (self::sources($dtoClass) as $binding) {
            foreach ($binding['keys'] as $key) {
                if (!array_key_exists($key, $data)) {
                    continue;
                }

                throw new RequestParameterSourceConflictException(
                    dtoClass: $dtoClass,
                    key: $key,
                    source: $binding['source'],
                    parameter: $binding['parameter'],
                );
            }
        }
    }

    /**
     * @param class-string $dtoClass
     * @return list<array{parameter: string, source: class-string, keys: list<string>}>
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
            $typeNames = TypeHints::classNames(
                $parameter->getType(),
                $parameter->getDeclaringClass(),
            );
            $source = self::declaredSource($parameter, $typeNames);

            if ($source === null) {
                continue;
            }

            $sources[] = [
                'parameter' => $parameter->getName(),
                'source' => $source,
                'keys' => array_values(array_unique([
                    $parameter->getName(),
                    ...$typeNames,
                ])),
            ];
        }

        return self::$sourceCache[$dtoClass] = $sources;
    }

    /**
     * @param list<class-string> $typeNames
     * @return class-string|null
     */
    private static function declaredSource(
        ReflectionParameter $parameter,
        array $typeNames,
    ): ?string {
        foreach ($parameter->getAttributes() as $attribute) {
            /** @var class-string $attributeClass */
            $attributeClass = $attribute->getName();

            if (is_a($attributeClass, ParameterSourceAttributeInterface::class, true)) {
                return $attributeClass;
            }
        }

        foreach ($typeNames as $typeName) {
            if ($typeName === ServerRequestInterface::class
                || $typeName === UriInterface::class
            ) {
                return $typeName;
            }
        }

        return null;
    }
}
