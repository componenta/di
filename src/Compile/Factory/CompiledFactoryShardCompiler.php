<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Internal\Compile\Factory\PlainConstructorFastPathPlanner;
use Componenta\DI\Object\ObjectPipeline;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/** Packs generated entry methods into immutable content-addressed shards. */
final readonly class CompiledFactoryShardCompiler
{
    public const int FORMAT_VERSION = 6;
    public const int DEFAULT_MAX_BYTES = 131072;
    public const string FILE_PREFIX = 'container.factories.';

    public function __construct(
        private FactoryCodeGenerator $factories,
        private string $pipelineFingerprint,
        private CompiledFactoryShardWriter $writer = new CompiledFactoryShardWriter(),
        private ?ObjectPipeline $objects = null,
        private ?PlainConstructorFastPathPlanner $fastPaths = null,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $pipelineFingerprint) !== 1) {
            throw new InvalidArgumentException('Compiled factory fingerprint must be a lowercase SHA-256 digest.');
        }
    }

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
            throw new InvalidArgumentException('Factory shard directory must be non-empty and maxBytes positive.');
        }
        self::assertNamespace($namespace);

        $fastPaths = $this->fastPaths;
        if ($fastPaths === null && $this->objects !== null) {
            $fastPaths = new PlainConstructorFastPathPlanner(
                $this->objects,
                $this->objects->parameters(),
            );
        }

        /** @var array<class-string, CompiledFactoryDefinition> $definitions */
        $definitions = [];
        /** @var list<GeneratedFactory> $current */
        $current = [];
        $size = 0;
        $index = 0;

        foreach ($classes as $class) {
            // Production compilation is a consumer of the same validated
            // metadata plan used by reflection runtime. Invalid combinations
            // therefore fail now, never only after deploying the shard.
            $this->objects?->prepare($class);
            if ($this->objects !== null && !$this->objects->canCreate($class)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot compile runtime-ineligible entry "%s".',
                    $class,
                ));
            }

            $plainAutowireTypes = $fastPaths?->plan($class);
            $factory = $this->factories->generate(
                $class,
                'createEntry' . $index++,
                plainAutowireTypes: $plainAutowireTypes,
            );
            $bytes = strlen($factory->code);
            if ($current !== [] && $size + $bytes > $maxBytes) {
                $this->writeShard($current, $directory, $namespace, $definitions);
                $current = [];
                $size = 0;
            }
            $current[] = $factory;
            $size += $bytes;
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
        $fastPaths = [];
        foreach ($shard as $factory) {
            if ($factory->plainAutowireTypes === null) {
                continue;
            }
            $fastPaths[$factory->method] = [
                'class' => $factory->class,
                'fingerprint' => PlainConstructorFastPathPlanner::fingerprint(
                    $factory->plainAutowireTypes,
                ),
            ];
        }

        $id = substr(hash('sha256', self::FORMAT_VERSION . "\0" . $namespace . "\0" . $payload), 0, 32);
        $class = 'CompiledFactoryShard_' . $id;
        $code = $this->code($namespace, $class, $payload, $fastPaths);
        $file = self::FILE_PREFIX . substr(hash('sha256', $code), 0, 32) . '.php';
        $this->writer->write(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file, $code);

        /** @var class-string $generatedClass */
        $generatedClass = $namespace . '\\' . $class;
        foreach ($shard as $factory) {
            $definitions[$factory->class] = new CompiledFactoryDefinition(
                $file,
                $generatedClass,
                $factory->method,
            );
        }
    }

    /**
     * @param array<string,array{class:class-string,fingerprint:string}> $fastPaths
     */
    private function code(
        string $namespace,
        string $class,
        string $methods,
        array $fastPaths,
    ): string {
        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

final class %s
{
    public const string PIPELINE_FINGERPRINT = %s;
    public const array FAST_PATHS = %s;

    public function __construct(
        private readonly \%s $objects,
        private readonly \%s $container,
    ) {}

%s
}

return %s::class;
PHP,
            $namespace,
            $class,
            var_export($this->pipelineFingerprint, true),
            var_export($fastPaths, true),
            ObjectPipeline::class,
            ContainerInterface::class,
            self::indent($methods, 4),
            $class,
        );
    }

    private static function assertNamespace(string $namespace): void
    {
        $identifier = '[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*';
        if ($namespace === ''
            || preg_match('/^(?:' . $identifier . ')(?:\\\\' . $identifier . ')*$/D', $namespace) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('Invalid generated namespace "%s".', $namespace));
        }
    }

    private static function indent(string $code, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);
        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
