<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Resolver\Entry\EntryClassEligibility;
use InvalidArgumentException;
use ReflectionClass;

/** Generates a thin AOT entry method that delegates semantic work to ObjectPipeline. */
final readonly class FactoryCodeGenerator
{
    /** @param class-string $class */
    public function generate(string $class, ?string $method = null): GeneratedFactory
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        if (!EntryClassEligibility::allows($reflection)) {
            throw new InvalidArgumentException(sprintf('Cannot compile ineligible entry "%s".', $class));
        }

        /** @var class-string $resolvedClass */
        $resolvedClass = $reflection->getName();
        $method ??= 'create_' . substr(hash('sha256', $resolvedClass), 0, 16);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid generated factory method "%s".', $method));
        }

        $code = sprintf(
            <<<'PHP'
/** @param array<string|int, mixed> $params */
public function %s(array $params = []): object
{
    return $this->objects->create(%s::class, $params);
}
PHP,
            $method,
            '\\' . $resolvedClass,
        );

        return new GeneratedFactory($resolvedClass, $method, $code);
    }
}
