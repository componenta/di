<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Error;
use Psr\Container\ContainerInterface;

/**
 * Materializes configured extensions through their declared callable or service route.
 *
 * @internal
 */
final class ExtensionFactory
{
    public static function parameterResolver(
        mixed $spec,
        ContainerInterface $container,
    ): ParameterResolverInterface {
        $resolver = self::materialize($spec, $container);
        if (!$resolver instanceof ParameterResolverInterface) {
            throw new InvalidConfigurationException(sprintf(
                'Expected %s, got %s.',
                ParameterResolverInterface::class,
                get_debug_type($resolver),
            ));
        }
        return $resolver;
    }

    public static function attributeDefinition(
        mixed $spec,
        ContainerInterface $container,
    ): AttributeDefinition {
        if ($spec instanceof AttributeDefinition) {
            return $spec;
        }
        $value = self::materialize($spec, $container);
        if (!$value instanceof AttributeDefinition) {
            throw new InvalidConfigurationException(sprintf(
                'Attribute definition factory returned %s.',
                get_debug_type($value),
            ));
        }
        return $value;
    }

    private static function materialize(mixed $spec, ContainerInterface $container): object
    {
        if (is_array($spec)) {
            // Own this invocation's routing before inspection or owner lookup
            // can rebind caller references; keep actual factory objects live.
            $snapshot = [];
            foreach ($spec as $key => $value) {
                $snapshot[$key] = $value;
            }
            $spec = $snapshot;
        }

        if ($spec instanceof ParameterResolverInterface || $spec instanceof AttributeDefinition) {
            return $spec;
        }

        if ($spec instanceof Closure) {
            $extension = $spec($container);
        } elseif (is_string($spec)) {
            $extension = $container->has($spec)
                ? $container->get($spec)
                : (is_callable($spec) ? $spec($container) : $container->get($spec));
        } elseif (is_array($spec)
            && !self::isNativeExtensionCallable($spec)
            && array_keys($spec) === [0, 1]
            && is_string($spec[0])
            && $spec[0] !== ''
            && is_string($spec[1])
            && $spec[1] !== ''
            && $container->has($spec[0])
        ) {
            $factory = [$container->get($spec[0]), $spec[1]];
            if (!is_callable($factory)) {
                throw new InvalidConfigurationException(sprintf(
                    'Extension service method "%s::%s" is not callable.',
                    $spec[0],
                    $spec[1],
                ));
            }
            $invoke = str_contains($spec[1], '::') ? Closure::fromCallable($factory) : $factory;
            $extension = $invoke($container);
        } elseif (is_callable($spec)) {
            // Native qualified ancestor pairs require capture before direct invocation.
            // Retain the original array until invocation completes, including static receivers.
            $invoke = is_array($spec) && str_contains($spec[1], '::') ? Closure::fromCallable($spec) : $spec;
            $extension = $invoke($container);
        } else {
            throw new InvalidConfigurationException(sprintf(
                'Unsupported extension specification %s.',
                get_debug_type($spec),
            ));
        }

        if (!is_object($extension)) {
            throw new InvalidConfigurationException(sprintf(
                'Extension factory returned %s instead of an object.',
                get_debug_type($extension),
            ));
        }

        return $extension;
    }

    /** @param array<mixed> $spec */
    private static function isNativeExtensionCallable(array $spec): bool
    {
        try {
            return is_callable($spec);
        } catch (Error $error) {
            if (!is_abstract_static_dispatch_error($error, __FILE__)) {
                throw $error;
            }
            return false;
        }
    }
}
