<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\InvalidConfigurationException;
use ReflectionClass;

/** Validates the class contract required by {@see InvokableResolver}. */
final class InvokableSpecificationValidator
{
    /** @phpstan-assert class-string $class */
    public static function assertValid(string $class): void
    {
        if ($class === '' || !class_exists($class)) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" is not loadable.',
                $class,
            ));
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $hasRequiredArguments = $constructor !== null
            && $constructor->getNumberOfRequiredParameters() > 0;
        $proxyAttribute = $reflection->getAttributes(Proxy::class)[0] ?? null;

        if ($proxyAttribute !== null) {
            $proxy = $proxyAttribute->newInstance();

            if (!$proxy instanceof Proxy || $proxy->class !== null) {
                throw new InvalidConfigurationException(
                    'Class-level #[Proxy] must not specify a proxy class; the marked class is used.',
                );
            }
        }

        $isLazy = $proxyAttribute === null
            && $reflection->getAttributes(Lazy::class) !== [];
        $isStructurallyConcrete = !$reflection->isAnonymous()
            && !$reflection->isInterface()
            && !$reflection->isTrait()
            && !$reflection->isAbstract()
            && !$reflection->isEnum();

        // Native lazy ghosts can initialize a private no-argument constructor
        // through reflection. Eager entries and virtual proxies need an
        // ordinarily instantiable class because their backing object is built
        // with `new $class()`.
        if (!$isStructurallyConcrete
            || $hasRequiredArguments
            || (!$isLazy && !$reflection->isInstantiable())
        ) {
            throw new InvalidConfigurationException(sprintf(
                'Invokable class "%s" must be concrete and require no constructor arguments.',
                $class,
            ));
        }
    }

    private function __construct() {}
}
