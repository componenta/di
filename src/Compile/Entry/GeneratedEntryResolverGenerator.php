<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Entry;

use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerator;
use Componenta\DI\Compile\Attribute\GeneratedAttributeCode;
use Componenta\DI\Compile\Factory\FactoryCode;
use Componenta\DI\Compile\Factory\FactoryCodeGenerator;
use Componenta\DI\Compile\Factory\GeneratedFactory;
use Componenta\DI\Compile\Parameter\EmptyContextResolution;
use Componenta\DI\Compile\Parameter\GeneratedParameterCode;
use Componenta\DI\Compile\Parameter\GeneratedResolverCode;
use Componenta\DI\Compile\Parameter\GeneratedResolverCodeType;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorInterface;
use Componenta\DI\Compile\Parameter\ParameterResolverCodeGeneratorRegistry;
use Componenta\DI\Compile\Parameter\PhpValueExporter;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Attribute\AttributeInvocation;
use Componenta\DI\Resolver\Attribute\CompilableAttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\EntryClassEligibility;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionResult;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\Target\ParameterTargetFactory;
use Componenta\DI\Resolver\TypeHints;
use Componenta\Reflection\ReflectionType;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;

/** Generates one EntryResolver class containing all known object factories. */
final readonly class GeneratedEntryResolverGenerator
{
    public const int FORMAT_VERSION = 2;

    /** Increment whenever the emitted class contract or factory layout changes. */
    public const int GENERATOR_VERSION = 15;

    public function __construct(
        private FactoryCodeGenerator $factories,
        private ParametersResolver $parameters,
        private AttributeProcessor $attributes,
        private ParameterResolverCodeGeneratorRegistry $parameterCodeGenerators,
    ) {}

    /**
     * @param iterable<class-string> $classes
     */
    public function generate(
        iterable $classes,
        string $namespace = 'Componenta\\DI\\Generated',
        ?string $releaseFingerprint = null,
    ): string {
        self::assertNamespace($namespace);
        if ($releaseFingerprint === '') {
            throw new InvalidArgumentException(
                'Generated resolver release fingerprint must be null or a non-empty string.',
            );
        }

        $entries = [];

        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidArgumentException('Generated entry class must be a non-empty class-string.');
            }

            $reflection = new ReflectionClass($class);
            if (!EntryClassEligibility::allows($reflection)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot generate an entry factory for ineligible entry "%s".',
                    $class,
                ));
            }

            $entries[$reflection->getName()] = true;
        }

        $entryClasses = array_keys($entries);
        sort($entryClasses, SORT_STRING);

        if ($entryClasses === []) {
            throw new InvalidArgumentException('At least one entry class is required.');
        }

        $parameterResolvers = $this->parameters->resolverList;
        $attributeHandlers = $this->attributes->registry->handlers;
        $parameterCodeGeneratorVersion = $this->parameterCodeGenerators->version;
        $parameterCodeGenerators = $this->parameterCodeGenerators->generatorList;

        $parameterTypes = GeneratedEntryResolverFingerprint::objectTypes(
            $parameterResolvers,
        );
        $handlerTypes = GeneratedEntryResolverFingerprint::objectTypes(
            $attributeHandlers,
        );
        $parameterCodeGeneratorTypes = GeneratedEntryResolverFingerprint::objectTypes(
            $parameterCodeGenerators,
        );
        $parameterFingerprint = GeneratedEntryResolverFingerprint::objects(
            $parameterResolvers,
        );
        $handlerFingerprint = GeneratedEntryResolverFingerprint::objects(
            $attributeHandlers,
        );
        $codeGeneratorFingerprint = GeneratedEntryResolverFingerprint::objects(
            $parameterCodeGenerators,
        );

        $sourceClasses = array_values(array_unique([
            ...$entryClasses,
            ...$this->attributeClasses($entryClasses),
            ...GeneratedEntryResolverFingerprint::objectSourceClasses($parameterResolvers),
            ...GeneratedEntryResolverFingerprint::objectSourceClasses($attributeHandlers),
            ...GeneratedEntryResolverFingerprint::objectSourceClasses($parameterCodeGenerators),
            self::class,
            FactoryCode::class,
            FactoryCodeGenerator::class,
            GeneratedFactory::class,
            ParameterCodeGenerator::class,
            ParametersResolver::class,
            ParameterCodeGenerationContext::class,
            ParameterResolverCodeGeneratorInterface::class,
            ParameterResolverCodeGeneratorRegistry::class,
            GeneratedParameterCode::class,
            GeneratedResolverCode::class,
            GeneratedResolverCodeType::class,
            EmptyContextResolution::class,
            PhpValueExporter::class,
            AttributeCodeGenerator::class,
            GeneratedAttributeCode::class,
            AttributeInvocation::class,
            AttributeProcessor::class,
            AttributeHandlerRegistry::class,
            GeneratedEntryResolverFingerprint::class,
            GeneratedEntryResolverLoader::class,
            EntryClassEligibility::class,
            EntryResolverInterface::class,
            ProxyFactoryInterface::class,
            ParameterResolverInterface::class,
            AttributeHandlerInterface::class,
            CompilableAttributeHandlerInterface::class,
            ObjectCreationContext::class,
            ParameterResolutionContext::class,
            ParameterResolutionResult::class,
            \Componenta\DI\Exception\ResolutionException::class,
            ParameterTarget::class,
            ParameterTargetFactory::class,
            CreationStrategy::class,
            AttributeCodeGenerationContext::class,
            ReflectionType::class,
            TypeHints::class,
        ]));
        sort($sourceClasses, SORT_STRING);

        $sourceFingerprint = GeneratedEntryResolverFingerprint::sources($sourceClasses);
        $factoryMethods = [];
        $matchArms = [];

        foreach ($entryClasses as $index => $entryClass) {
            $method = sprintf('createEntry%d', $index);
            $factory = $this->factories->generate($entryClass, $method);
            $factoryMethods[] = $factory->code;
            $matchArms[] = sprintf(
                '            \\%s::class => $this->%s($context),',
                $entryClass,
                $method,
            );
        }

        $currentParameterResolvers = $this->parameters->resolverList;
        $currentAttributeHandlers = $this->attributes->registry->handlers;
        $currentParameterCodeGenerators = $this->parameterCodeGenerators->generatorList;

        if ($parameterCodeGeneratorVersion !== $this->parameterCodeGenerators->version
            || $parameterTypes !== GeneratedEntryResolverFingerprint::objectTypes($currentParameterResolvers)
            || $handlerTypes !== GeneratedEntryResolverFingerprint::objectTypes($currentAttributeHandlers)
            || $parameterCodeGeneratorTypes !== GeneratedEntryResolverFingerprint::objectTypes($currentParameterCodeGenerators)
            || $parameterFingerprint !== GeneratedEntryResolverFingerprint::objects($currentParameterResolvers)
            || $handlerFingerprint !== GeneratedEntryResolverFingerprint::objects($currentAttributeHandlers)
            || $codeGeneratorFingerprint !== GeneratedEntryResolverFingerprint::objects($currentParameterCodeGenerators)
            || $sourceFingerprint !== GeneratedEntryResolverFingerprint::sources($sourceClasses)
        ) {
            throw new LogicException(
                'Resolver, handler and code-generator applicability must be pure; '
                . 'the extension pipeline changed state or source during generation.',
            );
        }

        $className = 'GeneratedEntryResolver_' . substr(hash('sha256', serialize([
            self::GENERATOR_VERSION,
            $entryClasses,
            $parameterTypes,
            $handlerTypes,
            $parameterCodeGeneratorTypes,
            $sourceClasses,
            $sourceFingerprint,
            $releaseFingerprint,
            $parameterFingerprint,
            $handlerFingerprint,
            $codeGeneratorFingerprint,
            hash('sha256', implode("\n\n", $factoryMethods)),
        ])), 0, 20);

        $template = <<<'GENERATED_PHP'
<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

if (!class_exists({{CLASS_NAME}}::class, false)) {
    final class {{CLASS_NAME}} implements \{{ENTRY_RESOLVER_INTERFACE}}
    {
        public const int FORMAT_VERSION = {{FORMAT_VERSION}};

        public const int GENERATOR_VERSION = {{GENERATOR_VERSION}};

        /** @var array<class-string, true> */
        private const array ENTRY_MAP = {{ENTRY_MAP}};

        /** @var list<class-string> */
        private const array SOURCE_CLASSES = {{SOURCE_CLASSES}};

        /** @var list<string> */
        private const array PARAMETER_RESOLVER_TYPES = {{PARAMETER_RESOLVER_TYPES}};

        /** @var list<string> */
        private const array ATTRIBUTE_HANDLER_TYPES = {{ATTRIBUTE_HANDLER_TYPES}};

        private const string SOURCE_FINGERPRINT = '{{SOURCE_FINGERPRINT}}';

        private const string RELEASE_FINGERPRINT = {{RELEASE_FINGERPRINT}};

        private const string PARAMETER_PIPELINE_FINGERPRINT = '{{PARAMETER_PIPELINE_FINGERPRINT}}';

        private const string ATTRIBUTE_PIPELINE_FINGERPRINT = '{{ATTRIBUTE_PIPELINE_FINGERPRINT}}';

        /** @param list<\{{PARAMETER_RESOLVER_INTERFACE}}> $parameterResolvers */
        public function __construct(
            private readonly array $parameterResolvers,
            private readonly array $attributeHandlers,
            private readonly \{{PROXY_FACTORY_INTERFACE}} $proxyFactory,
        ) {}

        public static function acceptsRuntime(
            array $parameterResolvers,
            array $attributeHandlers,
            ?string $releaseFingerprint = null,
        ): bool {
            return self::PARAMETER_RESOLVER_TYPES
                    === \{{FINGERPRINT_CLASS}}::objectTypes($parameterResolvers)
                && self::ATTRIBUTE_HANDLER_TYPES
                    === \{{FINGERPRINT_CLASS}}::objectTypes($attributeHandlers)
                && self::PARAMETER_PIPELINE_FINGERPRINT
                    === \{{FINGERPRINT_CLASS}}::objects($parameterResolvers)
                && self::ATTRIBUTE_PIPELINE_FINGERPRINT
                    === \{{FINGERPRINT_CLASS}}::objects($attributeHandlers)
                && ($releaseFingerprint === null
                    ? self::SOURCE_FINGERPRINT
                        === \{{FINGERPRINT_CLASS}}::sources(self::SOURCE_CLASSES)
                    : self::RELEASE_FINGERPRINT !== ''
                        && hash_equals(self::RELEASE_FINGERPRINT, $releaseFingerprint));
        }

        public function can(string $id): bool
        {
            return isset(self::ENTRY_MAP[$id]);
        }

        public function resolve(string $id, array $context = []): object
        {
            try {
                return match ($id) {
{{MATCH_ARMS}}
                    default => throw \{{NOT_FOUND_EXCEPTION}}::forService($id),
                };
            } catch (\Psr\Container\ContainerExceptionInterface|\{{RESOLUTION_EXCEPTION}} $error) {
                throw $error;
            } catch (\Throwable $error) {
                throw \{{RESOLUTION_EXCEPTION}}::forService($id, $error);
            }
        }

{{FACTORY_METHODS}}
    }
}

return {{CLASS_NAME}}::class;
GENERATED_PHP;

        return strtr($template, [
            '{{NAMESPACE}}' => $namespace,
            '{{CLASS_NAME}}' => $className,
            '{{ENTRY_RESOLVER_INTERFACE}}' => ltrim(EntryResolverInterface::class, '\\'),
            '{{FORMAT_VERSION}}' => (string) self::FORMAT_VERSION,
            '{{GENERATOR_VERSION}}' => (string) self::GENERATOR_VERSION,
            '{{ENTRY_MAP}}' => self::exportMap($entryClasses),
            '{{SOURCE_CLASSES}}' => self::exportArray($sourceClasses),
            '{{PARAMETER_RESOLVER_INTERFACE}}' => ltrim(ParameterResolverInterface::class, '\\'),
            '{{PARAMETER_RESOLVER_TYPES}}' => self::exportArray($parameterTypes),
            '{{ATTRIBUTE_HANDLER_TYPES}}' => self::exportArray($handlerTypes),
            '{{SOURCE_FINGERPRINT}}' => $sourceFingerprint,
            '{{RELEASE_FINGERPRINT}}' => var_export($releaseFingerprint ?? '', true),
            '{{PARAMETER_PIPELINE_FINGERPRINT}}' => $parameterFingerprint,
            '{{ATTRIBUTE_PIPELINE_FINGERPRINT}}' => $handlerFingerprint,
            '{{PROXY_FACTORY_INTERFACE}}' => ltrim(ProxyFactoryInterface::class, '\\'),
            '{{FINGERPRINT_CLASS}}' => ltrim(GeneratedEntryResolverFingerprint::class, '\\'),
            '{{MATCH_ARMS}}' => implode("\n", $matchArms),
            '{{NOT_FOUND_EXCEPTION}}' => ltrim(NotFoundException::class, '\\'),
            '{{RESOLUTION_EXCEPTION}}' => ltrim(\Componenta\DI\Exception\ResolutionException::class, '\\'),
            '{{FACTORY_METHODS}}' => self::indent(implode("\n\n", $factoryMethods), 4),
        ]);
    }

    /**
     * @param list<class-string> $entryClasses
     * @return list<class-string>
     */
    private function attributeClasses(array $entryClasses): array
    {
        $classes = [];

        foreach ($entryClasses as $entryClass) {
            foreach ($this->attributes->sourceAttributeClasses(
                new ReflectionClass($entryClass),
            ) as $attributeClass) {
                $classes[$attributeClass] = true;
            }
        }

        return array_keys($classes);
    }

    private static function assertNamespace(string $namespace): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid generated namespace "%s".',
                $namespace,
            ));
        }
    }

    /** @param list<string> $values */
    private static function exportArray(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        $lines = array_map(
            static fn(string $value): string => '            ' . var_export($value, true) . ',',
            $values,
        );

        return "[\n" . implode("\n", $lines) . "\n        ]";
    }

    /** @param list<string> $values */
    private static function exportMap(array $values): string
    {
        $lines = array_map(
            static fn(string $value): string => '            ' . var_export($value, true) . ' => true,',
            $values,
        );

        return "[\n" . implode("\n", $lines) . "\n        ]";
    }

    private static function indent(string $code, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);

        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
