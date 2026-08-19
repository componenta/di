<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Exception\InvalidConfigurationException;

/** Mutable collector for one generated factory and its support methods. */
final class FactoryCode
{
    /** @var array<string, string> */
    private array $methods = [];

    public string $code {
        get => implode("\n\n", array_values($this->methods));
    }

    public function addMethod(string $name, string $code): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid generated method name "%s".',
                $name,
            ));
        }

        if ($code === '') {
            throw new InvalidConfigurationException('Generated method code cannot be empty.');
        }

        if (array_key_exists($name, $this->methods)) {
            throw new InvalidConfigurationException(sprintf(
                'Generated method "%s" is already defined.',
                $name,
            ));
        }

        $this->methods[$name] = rtrim($code);
    }
}
