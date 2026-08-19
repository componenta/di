<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Throwable;

/** Compiles only declarative builder/config definitions; runtime set() state is not visible here. */
final readonly class DefinitionCompiler implements DefinitionCompilerInterface
{
    public function __construct(
        private DefinitionCodeGeneratorRegistry $generators,
    ) {}

    public static function createDefault(): self
    {
        return new self(new DefinitionCodeGeneratorRegistry());
    }

    public function compile(array $dependencies): array
    {
        $factories = $dependencies[ConfigKey::FACTORIES] ?? null;

        if (!is_array($factories)) {
            return $dependencies;
        }

        foreach ($factories as $id => $definition) {
            if (!$definition instanceof DefinitionInterface) {
                continue;
            }

            $generator = $this->generators->find($definition);
            if ($generator === null) {
                continue;
            }

            try {
                $factories[$id] = $generator->generate((string) $id, $definition);
            } catch (InvalidConfigurationException|CompilationException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new CompilationException(
                    sprintf(
                        'Definition code generator "%s" failed for "%s": %s',
                        $generator::class,
                        (string) $id,
                        $e->getMessage(),
                    ),
                    previous: $e,
                );
            }
        }

        $dependencies[ConfigKey::FACTORIES] = $factories;

        return $dependencies;
    }
}
