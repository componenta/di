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

        /** @var array<class-string,DefinitionCodeGeneratorInterface> $matches */
        $matches = [];
        foreach ($this->generators as $supportedClass => $generator) {
            if (is_a($definition, $supportedClass)) {
                $matches[$supportedClass] = $generator;
            }
        }

        if ($matches === []) {
            return null;
        }

        foreach (array_keys($matches) as $candidate) {
            foreach (array_keys($matches) as $other) {
                if ($candidate === $other) {
                    continue;
                }

                if (is_a($other, $candidate, true)) {
                    unset($matches[$candidate]);
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            return reset($matches);
        }

        throw new InvalidConfigurationException(sprintf(
            'Definition "%s" matches multiple equally specific code generators: %s.',
            $class,
            implode(', ', array_keys($matches)),
        ));
    }
}
