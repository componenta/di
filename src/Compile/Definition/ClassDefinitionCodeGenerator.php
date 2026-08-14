<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Definition;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Entry\FactorySpecificationValidator;
use Componenta\DI\Resolver\TypeHints;
use Componenta\VarExport\Export;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/** Compiles ClassDefinition into a reflection-free factory closure expression. */
final class ClassDefinitionCodeGenerator implements DefinitionCodeGeneratorInterface
{
    public function generate(
        string $id,
        DefinitionInterface $definition,
    ): GeneratedDefinitionCode {
        if (!$definition instanceof ClassDefinition) {
            throw new InvalidConfigurationException(sprintf(
                '%s cannot generate code for %s.',
                self::class,
                $definition::class,
            ));
        }

        FactorySpecificationValidator::assertValid($id, $definition);

        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass($definition->value);
        $className = $class->getName();

        if (!self::isSourceClassName($className)) {
            throw new InvalidConfigurationException(sprintf(
                'Class definition for "%s" targets class "%s" that cannot be emitted as PHP source.',
                $id,
                $className,
            ));
        }

        $body = [
            '$configured = ' . $this->valueExpression($definition->constructorParams, $id) . ';',
        ];
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            $body[] = sprintf('$entry = new \\%s();', $className);
        } else {
            $body[] = '$arguments = [];';

            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                    throw new InvalidConfigurationException(sprintf(
                        'Class definition for "%s" cannot be compiled because constructor parameter "$%s" is %s.',
                        $id,
                        $parameter->getName(),
                        $parameter->isVariadic() ? 'variadic' : 'passed by reference',
                    ));
                }

                $body[] = $this->compileConfiguredArgument($parameter, $class);
                $body[] = $this->compileRuntimeOverride($parameter, $class);
            }

            $body[] = sprintf('$entry = new \\%s(...$arguments);', $className);
        }

        foreach ($definition->methodCalls as $call) {
            $method = var_export($call['method'], true);
            $params = $this->valueExpression($call['params'], $id);
            $body[] = sprintf('$entry->{%s}(...%s);', $method, $params);
        }

        $body[] = 'return $entry;';

        return new GeneratedDefinitionCode(sprintf(
            "static function (\\Componenta\\Config\\ContainerValue \$container, array \$context = []): \\%s {\n%s\n}",
            $className,
            self::indent(implode("\n\n", $body)),
        ));
    }

    /** @param ReflectionClass<object> $declaringClass */
    private function compileConfiguredArgument(
        ReflectionParameter $parameter,
        ReflectionClass $declaringClass,
    ): string {
        $name = var_export($parameter->getName(), true);
        $position = $parameter->getPosition();
        $typeNames = TypeHints::classNames($parameter->getType(), $declaringClass);
        $parts = [
            sprintf("if (array_key_exists(%s, \$configured)) {\n    \$arguments[%s] = \$configured[%s];\n} elseif (array_key_exists(%d, \$configured)) {\n    \$arguments[%s] = \$configured[%d];\n}", $name, $name, $name, $position, $name, $position),
        ];

        if ($typeNames === []) {
            return $parts[0];
        }

        $candidate = '$configuredCandidate' . $position;
        $matched = '$configuredMatched' . $position;
        $typed = [sprintf('%s = false;', $matched)];
        $accepts = $this->objectAcceptanceExpression(
            $parameter->getType(),
            $declaringClass,
            $candidate,
        );

        foreach ($typeNames as $typeName) {
            $key = var_export($typeName, true);
            $typed[] = sprintf(
                "if (!%s && array_key_exists(%s, \$configured)) {\n    %s = \$configured[%s];\n    if (is_object(%s) && (%s)) {\n        \$arguments[%s] = %s;\n        %s = true;\n    }\n}",
                $matched,
                $key,
                $candidate,
                $key,
                $candidate,
                $accepts,
                $name,
                $candidate,
                $matched,
            );
        }

        return $parts[0] . " else {\n" . self::indent(implode("\n", $typed)) . "\n}";
    }

    /** @param ReflectionClass<object> $declaringClass */
    private function compileRuntimeOverride(
        ReflectionParameter $parameter,
        ReflectionClass $declaringClass,
    ): string {
        $name = var_export($parameter->getName(), true);
        $position = $parameter->getPosition();
        $typeNames = TypeHints::classNames($parameter->getType(), $declaringClass);
        $direct = sprintf(
            "if (array_key_exists(%s, \$context)) {\n    \$arguments[%s] = \$context[%s];\n} elseif (array_key_exists(%d, \$context)) {\n    \$arguments[%s] = \$context[%d];\n}",
            $name,
            $name,
            $name,
            $position,
            $name,
            $position,
        );

        if ($typeNames === []) {
            return $direct;
        }

        $candidate = '$contextCandidate' . $position;
        $matched = '$contextMatched' . $position;
        $typed = [sprintf('%s = false;', $matched)];
        $accepts = $this->objectAcceptanceExpression(
            $parameter->getType(),
            $declaringClass,
            $candidate,
        );

        foreach ($typeNames as $typeName) {
            $key = var_export($typeName, true);
            $typed[] = sprintf(
                "if (!%s && array_key_exists(%s, \$context)) {\n    %s = \$context[%s];\n    if (is_object(%s) && (%s)) {\n        \$arguments[%s] = %s;\n        %s = true;\n    }\n}",
                $matched,
                $key,
                $candidate,
                $key,
                $candidate,
                $accepts,
                $name,
                $candidate,
                $matched,
            );
        }

        return $direct . " else {\n" . self::indent(implode("\n", $typed)) . "\n}";
    }

    /** @param ReflectionClass<object> $declaringClass */
    private function objectAcceptanceExpression(
        ?ReflectionType $type,
        ReflectionClass $declaringClass,
        string $value,
    ): string {
        if ($type === null) {
            return 'true';
        }

        if ($type instanceof ReflectionNamedType) {
            if (!$type->isBuiltin()) {
                $class = self::resolveClassName($type->getName(), $declaringClass);

                return $class === null
                    ? 'false'
                    : sprintf('%s instanceof \\%s', $value, $class);
            }

            return match ($type->getName()) {
                'mixed', 'object' => 'true',
                'callable' => sprintf('is_callable(%s)', $value),
                'iterable' => sprintf('%s instanceof \\Traversable', $value),
                default => 'false',
            };
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $nested) {
                $parts[] = $this->objectAcceptanceExpression(
                    $nested,
                    $declaringClass,
                    $value,
                );
            }

            return '(' . implode(' || ', $parts) . ')';
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = [];
            foreach ($type->getTypes() as $nested) {
                $parts[] = $this->objectAcceptanceExpression(
                    $nested,
                    $declaringClass,
                    $value,
                );
            }

            return '(' . implode(' && ', $parts) . ')';
        }

        return 'false';
    }

    /** @param ReflectionClass<object> $declaringClass */
    private static function resolveClassName(
        string $name,
        ReflectionClass $declaringClass,
    ): ?string {
        if ($name === 'self' || $name === 'static') {
            return $declaringClass->getName();
        }

        if ($name === 'parent') {
            $parent = $declaringClass->getParentClass();

            return $parent === false ? null : $parent->getName();
        }

        return $name;
    }

    private function valueExpression(mixed $value, string $id): string
    {
        if ($value instanceof ReferenceDefinition) {
            return sprintf(
                '$container->get(%s)',
                var_export($value->value, true),
            );
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = sprintf(
                    '%s => %s',
                    var_export($key, true),
                    $this->valueExpression($item, $id),
                );
            }

            return '[' . implode(', ', $items) . ']';
        }

        try {
            return Export::var($value);
        } catch (Throwable $error) {
            throw new InvalidConfigurationException(sprintf(
                'Class definition for "%s" contains a value that cannot be emitted as PHP source: %s',
                $id,
                $error->getMessage(),
            ), previous: $error);
        }
    }

    private static function isSourceClassName(string $class): bool
    {
        return preg_match(
            '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D',
            $class,
        ) === 1;
    }

    private static function indent(string $code, int $level = 1): string
    {
        $indent = str_repeat('    ', $level);

        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
