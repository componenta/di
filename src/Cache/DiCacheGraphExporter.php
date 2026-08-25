<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Closure;
use Componenta\DI\Cache\Internal\GeneratedExpressionFormatter;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\VarExporter;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use UnitEnum;

/** Exports one cache graph while preserving repeated object and Closure identity. */
final class DiCacheGraphExporter
{
    private VarExporter $values;

    /** @var array<int, string> */
    private array $variables = [];

    /** @var array<int, true> */
    private array $building = [];

    /** @param array<int, true> $trustedGeneratedCode */
    public function __construct(
        private readonly ExportConfig $config,
        private readonly array $trustedGeneratedCode = [],
    ) {
        $this->values = new VarExporter($config);
    }

    /** @param array<string, mixed> $cache */
    public function export(array $cache): string
    {
        $this->variables = [];
        $this->building = [];
        $context = new ExportContext(0, baseIndent: $this->config->indent);
        $expression = $this->value($cache, $context);

        return "(static function (): array {\n"
            . $this->config->indent . "return {$expression};\n"
            . '})()';
    }

    private function value(mixed $value, ExportContext $context): string
    {
        if ($context->depth > $this->config->maxDepth) {
            throw new ExportException(
                sprintf(
                    'Maximum nesting depth of %d exceeded while exporting the DI cache graph at %s.',
                    $this->config->maxDepth,
                    $context->location(),
                ),
                [
                    'max_depth' => $this->config->maxDepth,
                    'depth' => $context->depth,
                    'path' => $context->path,
                ],
            );
        }

        return match (true) {
            is_array($value) => $this->array($value, $context),
            $value instanceof Closure => $this->object(
                $value,
                fn(): string => $this->values->getClosureExporter()->exportWithContext($value, $context),
            ),
            $value instanceof UnitEnum => $this->values->exportValue($value, $context),
            $value instanceof GeneratedDefinitionCode && $this->isTrusted($value) => $this->object(
                $value,
                fn(): string => GeneratedExpressionFormatter::indent($value->code, $context->baseIndent),
            ),
            is_object($value) => $this->object(
                $value,
                fn(): string => $this->readonlyObject($value, $context),
            ),
            default => $this->values->exportValue($value, $context),
        };
    }

    /** @param array<int|string, mixed> $values */
    private function array(array $values, ExportContext $context): string
    {
        if ($values === []) {
            return '[]';
        }

        $keys = array_keys($values);
        if ($this->config->sortKeys) {
            usort($keys, self::compareKeys(...));
        }

        $list = array_is_list($values);
        $items = [];
        $itemIndent = $context->baseIndent . $this->config->indent;
        foreach ($keys as $key) {
            if (\ReflectionReference::fromArrayElement($values, $key) !== null) {
                throw new ExportException(sprintf(
                    'DI cache array contains a PHP reference at key %s; alias semantics cannot be persisted safely.',
                    var_export($key, true),
                ));
            }

            $child = $context->child($key, $itemIndent);
            $item = $this->value($values[$key], $child);
            $items[] = $list ? $item : $this->values->export($key) . ' => ' . $item;
        }

        if (!$this->config->isPretty()) {
            return '[' . implode(', ', $items) . ']';
        }

        $trailing = $this->config->trailingComma ? ',' : '';

        return "[\n{$itemIndent}"
            . implode(",\n{$itemIndent}", $items)
            . "{$trailing}\n{$context->baseIndent}]";
    }

    private static function compareKeys(int|string $left, int|string $right): int
    {
        if (is_int($left) && is_string($right)) {
            return -1;
        }

        if (is_string($left) && is_int($right)) {
            return 1;
        }

        if (is_int($left)) {
            /** @var int $right */
            return $left <=> $right;
        }

        /** @var string $right */
        return strcmp($left, $right);
    }

    /** @param Closure(): string $expression */
    private function object(object $object, Closure $expression): string
    {
        $id = spl_object_id($object);
        if (isset($this->variables[$id])) {
            if (isset($this->building[$id])) {
                throw new ExportException(sprintf(
                    'Cyclic object graph containing "%s" cannot be exported.',
                    $object::class,
                ));
            }

            return $this->variables[$id];
        }

        $variable = '$componentaCacheObject' . count($this->variables);
        $this->variables[$id] = $variable;
        $this->building[$id] = true;

        try {
            $code = $expression();
        } finally {
            unset($this->building[$id]);
        }

        return sprintf('(%s = %s)', $variable, $code);
    }

    private function readonlyObject(object $object, ExportContext $context): string
    {
        $reflection = new ReflectionClass($object);
        $this->assertReconstructable($reflection, $object);

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return 'new \\' . $object::class . '()';
        }

        $arguments = [];
        $argumentIndent = $context->baseIndent . $this->config->indent;
        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $arguments[] = $this->value(
                $property->getValue($object),
                $context->child($parameter->getName(), $argumentIndent),
            );
        }

        return 'new \\' . $object::class . '(' . implode(', ', $arguments) . ')';
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertReconstructable(ReflectionClass $reflection, object $object): void
    {
        if (!$reflection->isReadOnly()) {
            throw new ExportException(sprintf(
                'Cannot export object of type "%s": only strict readonly constructor values are supported.',
                $object::class,
            ));
        }

        if ($reflection->isAnonymous()) {
            throw new ExportException(sprintf(
                'Cannot export anonymous readonly object of type "%s".',
                $object::class,
            ));
        }

        $constructor = $reflection->getConstructor();
        $properties = $this->instanceProperties($reflection);

        if ($constructor === null) {
            if ($properties !== []) {
                throw new ExportException(sprintf(
                    'Readonly object "%s" has state but no reconstructing constructor.',
                    $object::class,
                ));
            }

            return;
        }

        if (!$constructor->isPublic()) {
            throw new ExportException(sprintf(
                'Constructor of readonly object "%s" must be public.',
                $object::class,
            ));
        }

        $parameterNames = [];
        foreach ($constructor->getParameters() as $parameter) {
            $this->assertParameter($reflection, $object, $parameter);
            $parameterNames[$parameter->getName()] = true;
        }

        foreach ($properties as $property) {
            if (!isset($parameterNames[$property->getName()])) {
                throw new ExportException(sprintf(
                    'Readonly object "%s" has instance property "$%s" outside constructor state.',
                    $object::class,
                    $property->getName(),
                ));
            }
        }

        if ($reflection->hasMethod('__unserialize')) {
            throw new ExportException(sprintf(
                'Readonly object "%s" defines __unserialize(); generic constructor reconstruction is unsafe.',
                $object::class,
            ));
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<ReflectionProperty>
     */
    private function instanceProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        $class = $reflection;

        do {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                $properties[] = $property;
            }

            $class = $class->getParentClass();
        } while ($class !== false);

        return $properties;
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertParameter(
        ReflectionClass $reflection,
        object $object,
        ReflectionParameter $parameter,
    ): void {
        $name = $parameter->getName();

        if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
            throw new ExportException(sprintf(
                'Constructor parameter "%s::$%s" cannot be variadic or passed by reference.',
                $reflection->getName(),
                $name,
            ));
        }

        if (!$parameter->isPromoted() || !$reflection->hasProperty($name)) {
            throw new ExportException(sprintf(
                'Constructor parameter "%s::$%s" must be a promoted property.',
                $reflection->getName(),
                $name,
            ));
        }

        $property = $reflection->getProperty($name);
        if (!$property->isPublic() || !$property->isPromoted() || $property->isVirtual() || $property->hasHooks()) {
            throw new ExportException(sprintf(
                'Promoted property "%s::$%s" must be public, concrete and hook-free.',
                $reflection->getName(),
                $name,
            ));
        }

        if (!$property->isInitialized($object)) {
            throw new ExportException(sprintf(
                'Promoted property "%s::$%s" is not initialized.',
                $reflection->getName(),
                $name,
            ));
        }
    }

    private function isTrusted(GeneratedDefinitionCode $code): bool
    {
        return isset($this->trustedGeneratedCode[spl_object_id($code)]);
    }
}
