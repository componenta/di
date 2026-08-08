<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use InvalidArgumentException;

/** Ordered registry mapping resolver classes/interfaces to code generators. */
final class ParameterResolverCodeGeneratorRegistry
{
    /** @var array<class-string, ParameterResolverCodeGeneratorInterface> */
    private array $generators = [];

    public private(set) int $version = 0;

    /** @var list<ParameterResolverCodeGeneratorInterface> */
    public array $generatorList {
        get => array_values($this->generators);
    }

    /**
     * @param class-string<ParameterResolverInterface> $resolverClass
     */
    public function register(
        string $resolverClass,
        ParameterResolverCodeGeneratorInterface $generator,
    ): void {
        if (!is_a($resolverClass, ParameterResolverInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Resolver code-generator key "%s" must implement %s.',
                $resolverClass,
                ParameterResolverInterface::class,
            ));
        }

        $this->generators[$resolverClass] = $generator;
        ++$this->version;
    }

    public function find(
        ParameterResolverInterface $resolver,
    ): ?ParameterResolverCodeGeneratorInterface {
        $class = $resolver::class;

        if (isset($this->generators[$class])) {
            return $this->generators[$class];
        }

        foreach ($this->generators as $supportedClass => $generator) {
            if (is_a($resolver, $supportedClass)) {
                return $generator;
            }
        }

        return null;
    }
}
