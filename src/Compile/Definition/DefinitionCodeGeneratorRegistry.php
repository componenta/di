<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;

/** Maps definition classes/interfaces to their compile-time code generators. */
final class DefinitionCodeGeneratorRegistry
{
    /** @var array<class-string, DefinitionCodeGeneratorInterface> */
    private array $generators = [];

    /** @param class-string<DefinitionInterface> $definitionClass */
    public function register(
        string $definitionClass,
        DefinitionCodeGeneratorInterface $generator,
    ): void {
        if (!is_a($definitionClass, DefinitionInterface::class, true)) {
            throw new InvalidConfigurationException(sprintf(
                'Definition code-generator key "%s" must implement %s.',
                $definitionClass,
                DefinitionInterface::class,
            ));
        }

        $this->generators[$definitionClass] = $generator;
    }

    public function find(
        DefinitionInterface $definition,
    ): ?DefinitionCodeGeneratorInterface {
        $class = $definition::class;

        if (isset($this->generators[$class])) {
            return $this->generators[$class];
        }

        foreach ($this->generators as $supportedClass => $generator) {
            if (is_a($definition, $supportedClass)) {
                return $generator;
            }
        }

        return null;
    }
}
