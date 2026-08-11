<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Factory;

use Componenta\DI\Compile\Attribute\AttributeCodeGenerationContext;
use Componenta\DI\Compile\Attribute\AttributeCodeGenerator;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerationContext;
use Componenta\DI\Compile\Parameter\ParameterCodeGenerator;
use Componenta\DI\Resolver\Attribute\AttributeInvocation;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Entry\EntryClassEligibility;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;

/**
 * Generates one complete factory pipeline from the exact runtime parameter and
 * attribute metadata. Concrete attributes remain owned by their handlers.
 */
final class FactoryCodeGenerator
{
    private const int FAST_PATH_NONE = 0;
    private const int FAST_PATH_ALWAYS = 1;
    private const int FAST_PATH_EMPTY_PARAMETERS = 2;

    /** @var array<string, string> */
    private array $parameterHelperNames = [];

    /** @var array<string, true> */
    private array $requiredParameterHelpers = [];

    /** @var array<string, string> */
    private array $targetHelperNames = [];

    /** @var array<string, true> */
    private array $requiredTargetHelpers = [];

    private FactoryCode $factory;

    private ReflectionClass $class;

    private string $className;

    private string $prefix;

    private int $attributeSequence = 0;

    private int $fastPath = self::FAST_PATH_NONE;

    private bool $hasAttributeInvocations = false;

    private bool $constructorDisabled = false;

    public function __construct(
        private readonly ParameterCodeGenerator $parameters,
        private readonly AttributeProcessor $attributes,
        private readonly AttributeCodeGenerator $attributeCodeGenerator = new AttributeCodeGenerator(),
    ) {}

    /**
     * @param class-string $class
     */
    public function generate(string $class, ?string $method = null): GeneratedFactory
    {
        $reflection = new ReflectionClass($class);

        if (!EntryClassEligibility::allows($reflection)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot generate a factory for an ineligible entry "%s".',
                $class,
            ));
        }

        $this->reset($reflection);
        $method ??= $this->defaultFactoryMethod($class);
        self::assertMethodName($method);

        $invocations = $this->attributes->invocations($reflection);
        $this->hasAttributeInvocations = $invocations['before'] !== []
            || $invocations['after'] !== [];
        $beforeCode = $this->compileInvocations($invocations['before']);
        $afterCode = $this->compileInvocations($invocations['after']);

        if (!$this->hasAttributeInvocations) {
            $this->factory->addMethod(
                $method,
                $this->createPlainMethod($method),
            );
            $this->appendRequiredMetadataHelpers();

            return new GeneratedFactory(
                class: $class,
                method: $method,
                code: $this->factory->code,
            );
        }

        $instantiationMethod = $this->createInstantiationMethod();

        $this->factory->addMethod(
            $method,
            $this->createMethod($method, $beforeCode),
        );

        if ($this->fastPath !== self::FAST_PATH_ALWAYS) {
            $this->factory->addMethod(
                $this->eagerMethod(),
                $this->createEagerMethod(),
            );
            $this->factory->addMethod(
                $this->instantiateMethod(),
                $instantiationMethod,
            );
            $this->factory->addMethod(
                $this->completeMethod(),
                $this->createCompleteMethod($afterCode),
            );
            $this->factory->addMethod(
                $this->classHelper(),
                $this->createClassHelper(),
            );
        }

        $this->appendRequiredMetadataHelpers();

        return new GeneratedFactory(
            class: $class,
            method: $method,
            code: $this->factory->code,
        );
    }

    /** @param ReflectionClass<object> $class */
    private function reset(ReflectionClass $class): void
    {
        $this->factory = new FactoryCode();
        $this->class = $class;
        $this->className = $class->getName();
        $this->prefix = 'factory' . substr(hash('sha256', $this->className), 0, 12);
        $this->parameterHelperNames = [];
        $this->requiredParameterHelpers = [];
        $this->targetHelperNames = [];
        $this->requiredTargetHelpers = [];
        $this->attributeSequence = 0;
        $this->fastPath = self::FAST_PATH_NONE;
        $this->hasAttributeInvocations = false;
        $this->constructorDisabled = false;
        $this->parameterObjects = [];
        $this->targetObjects = [];
    }

    /**
     * @param list<AttributeInvocation> $invocations
     */
    private function compileInvocations(array $invocations): string
    {
        $parts = [];

        foreach ($invocations as $invocation) {
            $index = $this->attributeSequence++;
            $targetExpression = $this->targetExpression($invocation->target);
            $attributeHelper = $this->attributeHelper($index);
            $generated = $this->attributeCodeGenerator->generate(
                $invocation,
                new AttributeCodeGenerationContext(
                    creationExpression: '$creation',
                    entryExpression: '$entry',
                    handlerExpression: sprintf(
                        '$this->attributeHandlers[%d]',
                        $invocation->handlerSlot,
                    ),
                    attributeExpression: 'self::' . $attributeHelper . '()',
                    targetExpression: $targetExpression,
                    symbolPrefix: $this->prefix . 'Attribute' . $index,
                    parameters: $this->parameters,
                    parameterTargetExpressionResolver: fn(ReflectionParameter $parameter): string =>
                        'self::' . $this->parameterHelperName($parameter) . '()',
                    parameterTargetRequirement: function (ReflectionParameter $parameter): void {
                        $this->requireParameterHelper($parameter);
                    },
                ),
            );

            if ($generated->disablesConstructor) {
                $this->constructorDisabled = true;
            }

            if ($generated->usesAttribute) {
                $this->requireTargetHelper($invocation->target);
                $this->factory->addMethod(
                    $attributeHelper,
                    $this->createAttributeHelper($attributeHelper, $invocation),
                );
            }

            if ($generated->usesTarget) {
                $this->requireTargetHelper($invocation->target);
            }

            $parts[] = $generated->code;
        }

        return implode("\n\n", $parts);
    }

    /** Generates the common eager path without creation-strategy machinery. */
    private function createPlainMethod(string $method): string
    {
        $constructor = $this->class->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return sprintf(
                "public function %s(array \$parameters = []): \%s\n{\n    return new \%s();\n}",
                $method,
                $this->className,
                $this->className,
            );
        }

        [$parameterCode, $arguments, $usesNativeDefaults] = $this->compileConstructor($constructor);
        $body = [];

        if ($usesNativeDefaults) {
            $body[] = sprintf(
                "if (\$parameters === []) {\n    return new \%s();\n}",
                $this->className,
            );
        }

        $tryBody = [sprintf(
            '$context = new \%s($parameters);',
            ParameterResolutionContext::class,
        )];
        if ($parameterCode !== '') {
            $tryBody[] = $parameterCode;
        }
        $tryBody[] = sprintf(
            'return new \%s(%s);',
            $this->className,
            implode(', ', $arguments),
        );

        $body[] = sprintf(
            <<<'PHP'
try {
%s
} catch (\Psr\Container\ContainerExceptionInterface|\%s $error) {
    throw $error;
} catch (\Throwable $error) {
    throw \%s::forService(\%s::class, $error);
}
PHP,
            self::indent(implode("\n\n", $tryBody)),
            \Componenta\DI\Exception\ResolutionException::class,
            \Componenta\DI\Exception\ResolutionException::class,
            $this->className,
        );

        return sprintf(
            "public function %s(array \$parameters = []): \%s\n{\n%s\n}",
            $method,
            $this->className,
            self::indent(implode("\n\n", $body)),
        );
    }

    private function createMethod(string $method, string $beforeCode): string
    {
        if ($this->fastPath === self::FAST_PATH_ALWAYS) {
            return sprintf(
                "public function %s(array \$parameters = []): \%s\n{\n    return new \%s();\n}",
                $method,
                $this->className,
                $this->className,
            );
        }

        $body = [];

        if ($this->fastPath === self::FAST_PATH_EMPTY_PARAMETERS) {
            $body[] = sprintf(
                "if (\$parameters === []) {\n    return new \%s();\n}",
                $this->className,
            );
        }

        $body[] = sprintf(
            <<<'PHP'
$creation = new \%s(
    class: self::%s(),
    parameters: $parameters,
);
PHP,
            ObjectCreationContext::class,
            $this->classHelper(),
        );

        if ($beforeCode !== '') {
            $body[] = $beforeCode;
        }

        $body[] = sprintf(
            <<<'PHP'
return match ($creation->strategy) {
    \%s::Eager => $this->%s($creation),
    \%s::Lazy => $this->proxyFactory->makeLazy(
        \%s::class,
        function (object $entry) use ($creation): void {
            $attempt = $creation->freshAttempt();

            try {
                $entry = $this->%s($attempt, $entry);
                $this->%s($attempt, $entry);
            } catch (\Psr\Container\ContainerExceptionInterface|\%s $error) {
                throw $error;
            } catch (\Throwable $error) {
                throw \%s::forService(\%s::class, $error);
            }
        },
    ),
    \%s::Proxy => $this->proxyFactory->makeProxy(
        \%s::class,
        fn(object $proxy): object => $this->%s($creation->freshAttempt()),
    ),
};
PHP,
            CreationStrategy::class,
            $this->eagerMethod(),
            CreationStrategy::class,
            $this->className,
            $this->instantiateMethod(),
            $this->completeMethod(),
            \Componenta\DI\Exception\ResolutionException::class,
            \Componenta\DI\Exception\ResolutionException::class,
            $this->className,
            CreationStrategy::class,
            $this->className,
            $this->eagerMethod(),
        );

        return sprintf(
            "public function %s(array \$parameters = []): \\%s\n{\n%s\n}",
            $method,
            $this->className,
            self::indent(implode("\n\n", $body)),
        );
    }

    private function createEagerMethod(): string
    {
        return sprintf(
            <<<'PHP'
private function %s(\%s $creation): \%s
{
    try {
        $entry = $this->%s($creation);

        return $this->%s($creation, $entry);
    } catch (\Psr\Container\ContainerExceptionInterface|\%s $error) {
        throw $error;
    } catch (\Throwable $error) {
        throw \%s::forService(\%s::class, $error);
    }
}
PHP,
            $this->eagerMethod(),
            ObjectCreationContext::class,
            $this->className,
            $this->instantiateMethod(),
            $this->completeMethod(),
            \Componenta\DI\Exception\ResolutionException::class,
            \Componenta\DI\Exception\ResolutionException::class,
            $this->className,
        );
    }

    private function createInstantiationMethod(): string
    {
        $constructor = $this->class->getConstructor();
        $entryType = '\\' . $this->className;
        if ($this->constructorDisabled) {
            return sprintf(
                <<<'PHP'
private function %s(\%s $creation, ?\%s $entry = null): \%s
{
    if ($creation->constructorEnabled) {
        throw new \LogicException('Compiled constructor-disabled factory was not disabled.');
    }

    if ($entry !== null) {
        return $entry;
    }

    /** @var \%s $instance */
    $instance = self::%s()->newInstanceWithoutConstructor();

    return $instance;
}
PHP,
                $this->instantiateMethod(),
                ObjectCreationContext::class,
                $this->className,
                $this->className,
                $this->className,
                $this->classHelper(),
            );
        }

        $parts = [
            <<<'PHP'
if (!$creation->constructorEnabled) {
    if ($entry !== null) {
        return $entry;
    }

    /** @var ENTRY_CLASS $instance */
    $instance = self::CLASS_HELPER()->newInstanceWithoutConstructor();

    return $instance;
}
PHP,
        ];
        $parts[0] = str_replace(
            ['ENTRY_CLASS', 'CLASS_HELPER'],
            [$entryType, $this->classHelper()],
            $parts[0],
        );

        if ($constructor === null) {
            if (!$this->hasAttributeInvocations) {
                $this->fastPath = self::FAST_PATH_ALWAYS;
            }

            $parts[] = sprintf(
                <<<'PHP'
if ($entry !== null) {
    return $entry;
}

return new \%s();
PHP,
                $this->className,
            );
        } else {
            [$parameterCode, $arguments, $usesNativeDefaults] = $this->compileConstructor($constructor);

            if (!$this->hasAttributeInvocations) {
                if ($constructor->getNumberOfParameters() === 0) {
                    $this->fastPath = self::FAST_PATH_ALWAYS;
                } elseif ($usesNativeDefaults) {
                    $this->fastPath = self::FAST_PATH_EMPTY_PARAMETERS;
                }
            }

            if ($constructor->getNumberOfParameters() > 0) {
                $parts[] = sprintf(
                    '$context = new \\%s($creation->parameters);',
                    ParameterResolutionContext::class,
                );
            }

            if ($parameterCode !== '') {
                $parts[] = $parameterCode;
            }

            $parts[] = sprintf(
                <<<'PHP'
if ($entry !== null) {
    $entry->__construct(%s);

    return $entry;
}

return new \%s(%s);
PHP,
                implode(', ', $arguments),
                $this->className,
                implode(', ', $arguments),
            );
        }

        return sprintf(
            "private function %s(\\%s \$creation, ?\\%s \$entry = null): \\%s\n{\n%s\n}",
            $this->instantiateMethod(),
            ObjectCreationContext::class,
            $this->className,
            $this->className,
            self::indent(implode("\n\n", $parts)),
        );
    }

    /**
     * @return array{0: string, 1: list<string>, 2: bool}
     */
    private function compileConstructor(ReflectionMethod $constructor): array
    {
        $parts = [];
        $arguments = [];
        $usesNativeDefaults = true;

        foreach ($constructor->getParameters() as $parameter) {
            $position = $parameter->getPosition();
            $argument = sprintf('$%sConstructorArgument%d', $this->prefix, $position);
            $result = sprintf('$%sConstructorResult%d', $this->prefix, $position);
            $label = sprintf('%s_constructor_parameter_%d_resolved', $this->prefix, $position);
            $helper = $this->parameterHelperName($parameter);
            $generated = $this->parameters->generate(
                $parameter,
                new ParameterCodeGenerationContext(
                    contextExpression: '$context',
                    argumentVariable: $argument,
                    resultVariable: $result,
                    targetExpression: 'self::' . $helper . '()',
                    resolvedLabel: $label,
                ),
            );

            $usesNativeDefaults = $usesNativeDefaults
                && $generated->usesDeclaredDefaultWhenEmpty;

            if ($generated->usesTarget) {
                $this->requireParameterHelper($parameter);
            }

            $parts[] = $generated->code;
            $arguments[] = $argument;
        }

        return [implode("\n\n", $parts), $arguments, $usesNativeDefaults];
    }

    private function createCompleteMethod(string $afterCode): string
    {
        $body = ['$creation->initialize($entry);'];

        if ($afterCode !== '') {
            $body[] = $afterCode;
        }

        $body[] = 'return $entry;';

        return sprintf(
            "private function %s(\\%s \$creation, \\%s \$entry): \\%s\n{\n%s\n}",
            $this->completeMethod(),
            ObjectCreationContext::class,
            $this->className,
            $this->className,
            self::indent(implode("\n\n", $body)),
        );
    }

    private function createClassHelper(): string
    {
        return sprintf(
            <<<'PHP'
private static function %s(): \ReflectionClass
{
    static $class = null;

    return $class ??= new \ReflectionClass(\%s::class);
}
PHP,
            $this->classHelper(),
            $this->className,
        );
    }

    private function createAttributeHelper(
        string $helper,
        AttributeInvocation $invocation,
    ): string {
        $target = $this->targetExpression($invocation->target);

        return sprintf(
            <<<'PHP'
private static function %s(): object
{
    $reflection = %s->getAttributes()[%d] ?? throw new \LogicException(%s);

    if ($reflection->getName() !== %s) {
        throw new \LogicException(%s);
    }

    return $reflection->newInstance();
}
PHP,
            $helper,
            $target,
            $invocation->attributeIndex,
            var_export(sprintf(
                'Compiled attribute #%d is missing on %s.',
                $invocation->attributeIndex,
                self::targetDescription($invocation->target),
            ), true),
            var_export($invocation->attributeClass, true),
            var_export(sprintf(
                'Compiled attribute #%d changed on %s.',
                $invocation->attributeIndex,
                self::targetDescription($invocation->target),
            ), true),
        );
    }

    private function appendRequiredMetadataHelpers(): void
    {
        foreach ($this->requiredTargetHelpers as $key => $_) {
            $target = $this->targetFromKey($key);
            $helper = $this->targetHelperNames[$key];
            $this->factory->addMethod(
                $helper,
                $this->createTargetHelper($helper, $target),
            );
        }

        foreach ($this->requiredParameterHelpers as $key => $_) {
            $parameter = $this->parameterFromKey($key);
            $helper = $this->parameterHelperNames[$key];
            $this->factory->addMethod(
                $helper,
                $this->createParameterHelper($helper, $parameter),
            );
        }
    }

    private function createTargetHelper(string $helper, Reflector $target): string
    {
        return match (true) {
            $target instanceof ReflectionProperty => sprintf(
                <<<'PHP'
private static function %s(): \ReflectionProperty
{
    static $property = null;

    return $property ??= new \ReflectionProperty(\%s::class, %s);
}
PHP,
                $helper,
                $target->getDeclaringClass()->getName(),
                var_export($target->getName(), true),
            ),
            $target instanceof ReflectionMethod => sprintf(
                <<<'PHP'
private static function %s(): \ReflectionMethod
{
    static $method = null;

    return $method ??= new \ReflectionMethod(\%s::class, %s);
}
PHP,
                $helper,
                $target->getDeclaringClass()->getName(),
                var_export($target->getName(), true),
            ),
            $target instanceof ReflectionClass => sprintf(
                <<<'PHP'
private static function %s(): \ReflectionClass
{
    static $class = null;

    return $class ??= new \ReflectionClass(\%s::class);
}
PHP,
                $helper,
                $target->getName(),
            ),
            default => throw new LogicException(sprintf(
                'Unsupported compiled attribute target %s.',
                get_debug_type($target),
            )),
        };
    }

    private function createParameterHelper(
        string $helper,
        ReflectionParameter $parameter,
    ): string {
        $class = $parameter->getDeclaringClass()
            ?? throw new LogicException('Generated factories support only method parameters.');
        $method = $parameter->getDeclaringFunction()->getName();

        return sprintf(
            <<<'PHP'
private static function %s(): \%s
{
    static $target = null;

    if ($target !== null) {
        return $target;
    }

    $parameters = (new \ReflectionMethod(\%s::class, %s))->getParameters();
    $parameter = $parameters[%d] ?? throw new \LogicException(%s);

    return $target = new \%s($parameter);
}
PHP,
            $helper,
            ParameterTarget::class,
            $class->getName(),
            var_export($method, true),
            $parameter->getPosition(),
            var_export(sprintf(
                'Compiled parameter %s::%s() #%d is missing.',
                $class->getName(),
                $method,
                $parameter->getPosition(),
            ), true),
            ParameterTarget::class,
        );
    }

    private function parameterHelperName(ReflectionParameter $parameter): string
    {
        $key = self::parameterKey($parameter);

        return $this->parameterHelperNames[$key]
            ??= $this->prefix . 'Parameter' . count($this->parameterHelperNames);
    }

    private function requireParameterHelper(ReflectionParameter $parameter): void
    {
        $key = self::parameterKey($parameter);
        $this->parameterHelperName($parameter);
        $this->requiredParameterHelpers[$key] = true;
        $this->parameterObjects[$key] = $parameter;
    }

    /** @var array<string, ReflectionParameter> */
    private array $parameterObjects = [];

    private function parameterFromKey(string $key): ReflectionParameter
    {
        return $this->parameterObjects[$key]
            ?? throw new LogicException(sprintf('Unknown parameter helper key "%s".', $key));
    }

    private function targetExpression(Reflector $target): string
    {
        if ($target instanceof ReflectionClass
            && $target->getName() === $this->className
        ) {
            return 'self::' . $this->classHelper() . '()';
        }

        return 'self::' . $this->targetHelperName($target) . '()';
    }

    private function targetHelperName(Reflector $target): string
    {
        $key = self::targetKey($target);

        return $this->targetHelperNames[$key]
            ??= $this->prefix . 'Target' . count($this->targetHelperNames);
    }

    private function requireTargetHelper(Reflector $target): void
    {
        if ($target instanceof ReflectionClass
            && $target->getName() === $this->className
        ) {
            return;
        }

        $key = self::targetKey($target);
        $this->targetHelperName($target);
        $this->requiredTargetHelpers[$key] = true;
        $this->targetObjects[$key] = $target;
    }

    /** @var array<string, Reflector> */
    private array $targetObjects = [];

    private function targetFromKey(string $key): Reflector
    {
        return $this->targetObjects[$key]
            ?? throw new LogicException(sprintf('Unknown target helper key "%s".', $key));
    }

    private function classHelper(): string
    {
        return $this->prefix . 'Class';
    }

    private function eagerMethod(): string
    {
        return $this->prefix . 'Eager';
    }

    private function instantiateMethod(): string
    {
        return $this->prefix . 'Instantiate';
    }

    private function completeMethod(): string
    {
        return $this->prefix . 'Complete';
    }

    private function attributeHelper(int $index): string
    {
        return $this->prefix . 'Attribute' . $index;
    }

    private function defaultFactoryMethod(string $class): string
    {
        $short = strrchr($class, '\\');
        $short = $short === false ? $class : substr($short, 1);
        $short = preg_replace('/[^A-Za-z0-9_]/', '_', $short) ?: 'Entry';

        if (preg_match('/^[A-Za-z_]/', $short) !== 1) {
            $short = '_' . $short;
        }

        return 'create' . $short . substr(hash('sha256', $class), 0, 8);
    }

    private static function parameterKey(ReflectionParameter $parameter): string
    {
        $class = $parameter->getDeclaringClass()?->getName() ?? '';

        return $class
            . "\0"
            . $parameter->getDeclaringFunction()->getName()
            . "\0"
            . $parameter->getPosition();
    }

    private static function targetKey(Reflector $target): string
    {
        return match (true) {
            $target instanceof ReflectionClass => 'class:' . $target->getName(),
            $target instanceof ReflectionProperty => sprintf(
                'property:%s:%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof ReflectionMethod => sprintf(
                'method:%s:%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            default => throw new LogicException(sprintf(
                'Unsupported target %s.',
                get_debug_type($target),
            )),
        };
    }

    private static function targetDescription(Reflector $target): string
    {
        return match (true) {
            $target instanceof ReflectionClass => $target->getName(),
            $target instanceof ReflectionProperty => sprintf(
                '%s::$%s',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            $target instanceof ReflectionMethod => sprintf(
                '%s::%s()',
                $target->getDeclaringClass()->getName(),
                $target->getName(),
            ),
            default => get_debug_type($target),
        };
    }

    private static function assertMethodName(string $method): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $method) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid generated factory method "%s".',
                $method,
            ));
        }
    }

    private static function indent(string $code, int $spaces = 4): string
    {
        $indent = str_repeat(' ', $spaces);

        return $indent . str_replace("\n", "\n" . $indent, $code);
    }
}
