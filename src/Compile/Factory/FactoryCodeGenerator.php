<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Exception\InvalidConfigurationException;
use ReflectionClass;

use function Componenta\DI\is_entry_class_eligible;

/** Generates thin AOT entry methods that delegate execution to ObjectPipeline. */
final readonly class FactoryCodeGenerator
{
    /** @param class-string $class */
    public function generate(string $class, ?string $method = null): GeneratedFactory
    {
        if (!class_exists($class)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot compile unavailable entry "%s".',
                $class,
            ));
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        if (!is_entry_class_eligible($reflection)) {
            throw new InvalidConfigurationException(sprintf(
                'Cannot compile ineligible entry "%s".',
                $class,
            ));
        }

        /** @var class-string $resolvedClass */
        $resolvedClass = $reflection->getName();
        $method ??= 'create_' . substr(hash('sha256', $resolvedClass), 0, 16);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
            throw new InvalidConfigurationException(sprintf(
                'Invalid generated factory method "%s".',
                $method,
            ));
        }

        $code = sprintf(
            <<<'PHP'
/** @param array<string|int, mixed> $params */
public function %s(array $params = []): object
{
    return $this->objects->create(\%s::class, $params);
}
PHP,
            $method,
            $resolvedClass,
        );

        return new GeneratedFactory(
            $resolvedClass,
            $method,
            $code,
        );
    }
}
