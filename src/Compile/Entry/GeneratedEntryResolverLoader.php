<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Entry;

use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Throwable;

/** Loads a generated resolver only when its format, sources and slot order match. */
final class GeneratedEntryResolverLoader
{
    /**
     * @param list<ParameterResolverInterface> $parameterResolvers
     * @param list<AttributeHandlerInterface> $attributeHandlers
     */
    public function load(
        string $file,
        array $parameterResolvers,
        array $attributeHandlers,
        ProxyFactoryInterface $proxyFactory,
        ?string $releaseFingerprint = null,
    ): ?EntryResolverInterface {
        if ($file === '' || !is_file($file)) {
            return null;
        }

        try {
            $class = require $file;

            if (!is_string($class)
                || !is_subclass_of($class, EntryResolverInterface::class)
                || !defined($class . '::FORMAT_VERSION')
                || !defined($class . '::GENERATOR_VERSION')
                || $class::FORMAT_VERSION !== GeneratedEntryResolverGenerator::FORMAT_VERSION
                || $class::GENERATOR_VERSION !== GeneratedEntryResolverGenerator::GENERATOR_VERSION
                || !method_exists($class, 'acceptsRuntime')
                || !$class::acceptsRuntime(
                    $parameterResolvers,
                    $attributeHandlers,
                    $releaseFingerprint,
                )
            ) {
                return null;
            }

            $resolver = new $class(
                array_values($parameterResolvers),
                array_values($attributeHandlers),
                $proxyFactory,
            );

            return $resolver instanceof EntryResolverInterface ? $resolver : null;
        } catch (Throwable) {
            return null;
        }
    }
}
