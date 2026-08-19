<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use InvalidArgumentException;
use ReflectionClass;

use function Componenta\DI\is_entry_class_eligible;

/** Generates a thin AOT entry method that delegates semantic work to ObjectPipeline. */
final readonly class FactoryCodeGenerator
{
    /** @param class-string $class */
    public function generate(string $class, ?string $method = null, bool $direct = false): GeneratedFactory
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        if (!is_entry_class_eligible($reflection)) {
            throw new InvalidArgumentException(sprintf('Cannot compile ineligible entry "%s".', $class));
        }

        /** @var class-string $resolvedClass */
        $resolvedClass = $reflection->getName();
        $method ??= 'create_' . substr(hash('sha256', $resolvedClass), 0, 16);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid generated factory method "%s".', $method));
        }

        $body = $direct
            ? sprintf('return new \\%s();', $resolvedClass)
            : sprintf('return $this->objects->create(\\%s::class, $params);', $resolvedClass);
        $code = sprintf(
            <<<'PHP'
/** @param array<string|int, mixed> $params */
public function %s(array $params = []): object
{
    %s
}
PHP,
            $method,
            $body,
        );

        return new GeneratedFactory($resolvedClass, $method, $code);
    }
}
