<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Closure;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Exception\ExportException;
use Componenta\VarExport\VarExporter;
use ReflectionClass;
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
        $expression = $this->value($cache, 1);

        return "(static function (): array {\n"
            . $this->config->indent . "return {$expression};\n"
            . '})()';
    }

    private function value(mixed $value, int $depth): string
    {
        if ($depth > $this->config->maxDepth) {
            throw new ExportException(sprintf(
                'Maximum nesting depth of %d exceeded while exporting the DI cache graph.',
                $this->config->maxDepth,
            ));
        }

        return match (true) {
            is_array($value) => $this->array($value, $depth),
            $value instanceof Closure => $this->object(
                $value,
                fn(): string => $this->values->getClosureExporter()->exportWithDepth($value, $depth),
            ),
            $value instanceof UnitEnum => $this->values->export($value),
            $value instanceof GeneratedDefinitionCode && $this->isTrusted($value) => $this->object(
                $value,
                static fn(): string => $value->code,
            ),
            is_object($value) => $this->object(
                $value,
                fn(): string => $this->readonlyObject($value, $depth),
            ),
            default => $this->values->export($value),
        };
    }

    /** @param array<int|string, mixed> $values */
    private function array(array $values, int $depth): string
    {
        if ($values === []) {
            return '[]';
        }

        $keys = array_keys($values);
        if ($this->config->sortKeys) {
            usort($keys, static fn(int|string $left, int|string $right): int => $left <=> $right);
        }

        $list = array_is_list($values);
        $items = [];
        foreach ($keys as $key) {
            $item = $this->value($values[$key], $depth + 1);
            $items[] = $list ? $item : $this->values->export($key) . ' => ' . $item;
        }

        if (!$this->config->isPretty()) {
            return '[' . implode(', ', $items) . ']';
        }

        $itemIndent = str_repeat($this->config->indent, $depth);
        $baseIndent = str_repeat($this->config->indent, $depth - 1);
        $trailing = $this->config->trailingComma ? ',' : '';

        return "[\n{$itemIndent}"
            . implode(",\n{$itemIndent}", $items)
            . "{$trailing}\n{$baseIndent}]";
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

    private function readonlyObject(object $object, int $depth): string
    {
        $reflection = new ReflectionClass($object);
        if (!$reflection->isReadOnly()) {
            throw new ExportException(sprintf(
                'Cannot export object of type "%s": only readonly classes are supported.',
                $object::class,
            ));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return 'new \\' . $object::class . '()';
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (!$reflection->hasProperty($name)) {
                throw new ExportException(sprintf(
                    'Cannot export object of type "%s": constructor parameter "$%s" has no corresponding public promoted property.',
                    $object::class,
                    $name,
                ));
            }

            $property = $reflection->getProperty($name);
            if (!$property->isPublic() || !$property->isPromoted()) {
                throw new ExportException(sprintf(
                    'Cannot export object of type "%s": constructor parameter "$%s" must be a public promoted property to guarantee an exact cache round trip.',
                    $object::class,
                    $name,
                ));
            }

            $arguments[] = $this->value(
                $property->getValue($object),
                $depth + 1,
            );
        }

        return 'new \\' . $object::class . '(' . implode(', ', $arguments) . ')';
    }

    private function isTrusted(GeneratedDefinitionCode $code): bool
    {
        return isset($this->trustedGeneratedCode[spl_object_id($code)]);
    }
}
