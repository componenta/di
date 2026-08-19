<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use InvalidArgumentException;
use ReflectionClass;

use function Componenta\DI\is_entry_class_eligible;

/** Generates thin AOT entry methods with conservative plain-constructor fast paths. */
final readonly class FactoryCodeGenerator
{
    /**
     * @param class-string $class
     * @param list<class-string>|null $plainAutowireTypes
     */
    public function generate(
        string $class,
        ?string $method = null,
        bool $direct = false,
        ?array $plainAutowireTypes = null,
    ): GeneratedFactory {
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

        if ($plainAutowireTypes !== null) {
            $body = self::plainConstructorBody($resolvedClass, $plainAutowireTypes);
        } else {
            $body = $direct
                ? sprintf('return new \\%s();', $resolvedClass)
                : self::fallbackBody($resolvedClass);
        }

        $code = sprintf(
            <<<'PHP'
/** @param array<string|int, mixed> $params */
public function %s(array $params = []): object
{
%s
}
PHP,
            $method,
            self::indent($body, 4),
        );

        return new GeneratedFactory(
            $resolvedClass,
            $method,
            $code,
            $plainAutowireTypes,
        );
    }

    /**
     * @param class-string $class
     * @param list<class-string> $types
     */
    private static function plainConstructorBody(string $class, array $types): string
    {
        $fallback = self::fallbackBody($class);
        if ($types === []) {
            return sprintf(
                "if (\$params === []) {\n    return new \\%s();\n}\n\n%s",
                $class,
                $fallback,
            );
        }

        $lines = ['if ($params === []) {'];
        $indent = 1;
        $arguments = [];

        foreach ($types as $index => $type) {
            $variable = '$dependency' . $index;
            $arguments[] = $variable;
            $padding = str_repeat('    ', $indent);
            $lines[] = sprintf(
                '%sif ($this->container->has(\\%s::class)) {',
                $padding,
                $type,
            );
            ++$indent;
            $padding = str_repeat('    ', $indent);
            $lines[] = sprintf(
                '%s%s = $this->container->get(\\%s::class);',
                $padding,
                $variable,
                $type,
            );
            $lines[] = sprintf(
                '%sif (%s instanceof \\%s) {',
                $padding,
                $variable,
                $type,
            );
            ++$indent;
        }

        $lines[] = sprintf(
            '%sreturn new \\%s(%s);',
            str_repeat('    ', $indent),
            $class,
            implode(', ', $arguments),
        );

        for ($index = count($types) - 1; $index >= 0; --$index) {
            --$indent;
            $lines[] = str_repeat('    ', $indent) . '}';
            --$indent;
            $lines[] = str_repeat('    ', $indent) . '}';
        }
        $lines[] = '}';
        $lines[] = '';
        $lines[] = $fallback;

        return implode("\n", $lines);
    }

    /** @param class-string $class */
    private static function fallbackBody(string $class): string
    {
        return sprintf(
            'return $this->objects->create(\\%s::class, $params);',
            $class,
        );
    }

    private static function indent(string $code, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);
        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
