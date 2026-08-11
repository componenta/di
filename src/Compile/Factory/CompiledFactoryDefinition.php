<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Definition\DefinitionInterface;

/** Lazy reference to one method in a generated factory shard. */
final readonly class CompiledFactoryDefinition implements DefinitionInterface
{
    private const string PREFIX = "\0componenta.compiled-factory\0";

    public string $value;

    /** @param class-string $class */
    public function __construct(
        public string $file,
        public string $class,
        public string $method,
    ) {
        $this->value = $method;
    }

    /** Compact cache representation; decoded only when the entry is resolved. */
    public function encode(): string
    {
        return self::PREFIX . $this->file . "\0" . $this->class . "\0" . $this->method;
    }

    public static function isEncodedValue(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    public static function decode(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!self::isEncodedValue($value)) {
            return null;
        }

        /** @var string $value */
        $parts = explode("\0", substr($value, strlen(self::PREFIX)), 3);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return null;
        }

        [$file, $class, $method] = $parts;

        if (!self::isClassName($class) || !self::isMethodName($method)) {
            return null;
        }

        /** @var class-string $class */
        return new self($file, $class, $method);
    }

    private static function isClassName(string $class): bool
    {
        return preg_match(
            '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)'
            . '(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D',
            $class,
        ) === 1;
    }

    private static function isMethodName(string $method): bool
    {
        return preg_match(
            '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D',
            $method,
        ) === 1;
    }
}
