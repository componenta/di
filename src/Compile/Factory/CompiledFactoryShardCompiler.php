<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use InvalidArgumentException;

/** Packs generated methods into content-addressed, independently loadable factory shards. */
final readonly class CompiledFactoryShardCompiler
{
    public const int FORMAT_VERSION = 2;
    public const int DEFAULT_MAX_BYTES = 131072;
    public const string FILE_PREFIX = 'container.factories.';

    public function __construct(
        private FactoryCodeGenerator $factories,
        private CompiledFactoryShardWriter $writer = new CompiledFactoryShardWriter(),
    ) {}

    /**
     * @param iterable<class-string> $classes
     * @return array<class-string, CompiledFactoryDefinition>
     */
    public function compile(
        iterable $classes,
        string $directory,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        string $namespace = 'Componenta\\DI\\Generated',
    ): array {
        if ($directory === '' || $maxBytes < 1) {
            throw new InvalidArgumentException('Factory shard directory must be non-empty and maxBytes must be positive.');
        }

        self::assertNamespace($namespace);

        $definitions = [];
        $current = [];
        $size = 0;
        $index = 0;

        foreach ($classes as $class) {
            $factory = $this->factories->generate($class, 'createEntry' . $index++);
            $factoryBytes = strlen($factory->code);

            if ($current !== [] && $size + $factoryBytes > $maxBytes) {
                $this->writeShard($current, $directory, $namespace, $definitions);
                $current = [];
                $size = 0;
            }

            $current[] = $factory;
            $size += $factoryBytes;
        }

        if ($current !== []) {
            $this->writeShard($current, $directory, $namespace, $definitions);
        }

        return $definitions;
    }

    /**
     * @param list<GeneratedFactory> $shard
     * @param array<class-string, CompiledFactoryDefinition> $definitions
     */
    private function writeShard(
        array $shard,
        string $directory,
        string $namespace,
        array &$definitions,
    ): void {
        $payload = implode("\n\n", array_map(
            static fn(GeneratedFactory $factory): string => $factory->code,
            $shard,
        ));
        $id = substr(hash('sha256', self::FORMAT_VERSION . "\0" . $namespace . "\0" . $payload), 0, 32);
        $class = 'CompiledFactoryShard_' . $id;
        $code = $this->code($namespace, $class, $payload);
        $file = self::FILE_PREFIX . substr(hash('sha256', $code), 0, 32) . '.php';
        $this->writer->write(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file, $code);

        /** @var class-string $generatedClass */
        $generatedClass = $namespace . '\\' . $class;

        foreach ($shard as $factory) {
            $definitions[$factory->class] = new CompiledFactoryDefinition(
                file: $file,
                class: $generatedClass,
                method: $factory->method,
            );
        }
    }

    private function code(string $namespace, string $class, string $methods): string
    {
        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

final class %s
{
    public function __construct(
        private readonly array $parameterResolvers,
        private readonly array $attributeHandlers,
        private readonly \%s $proxyFactory,
    ) {}

%s
}

return %s::class;
PHP,
            $namespace,
            $class,
            \Componenta\DI\ProxyFactoryInterface::class,
            self::indent($methods, 4),
            $class,
        );
    }

    private static function assertNamespace(string $namespace): void
    {
        $identifier = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

        if ($namespace === ''
            || preg_match('/^(?:' . $identifier . ')(?:\\\\' . $identifier . ')*$/D', $namespace) !== 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'Factory shard namespace "%s" is not a valid PHP namespace.',
                $namespace,
            ));
        }
    }

    private static function indent(string $code, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);

        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
