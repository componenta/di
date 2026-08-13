<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use Throwable;

/** Resolves registered classes whose constructors require no arguments. */
class InvokableResolver implements DefinitionAwareResolverInterface, DefinitionRemovalInterface
{
    /** @var array<string, class-string> */
    private array $invokables = [];

    /** @var array<class-string, CreationStrategy> */
    private array $strategyCache = [];

    private readonly ParametersResolver $contextResolver;

    /** @param list<class-string> $invokables */
    public function __construct(
        array $invokables = [],
        private readonly ?ProxyFactoryInterface $proxyFactory = null,
        private readonly ?AttributeProcessor $attributeProcessor = null,
    ) {
        $this->contextResolver = new ParametersResolver(
            new ArrayResolver(),
            new ArrayTypedResolver(),
            new DefaultValueResolver(),
            new NullableResolver(),
        );

        foreach ($invokables as $class) {
            InvokableSpecificationValidator::assertValid($class, $this->attributeProcessor);
            $this->invokables[$class] = $class;
        }
    }

    public function can(string $id): bool
    {
        return isset($this->invokables[$id]);
    }

    /** @param array<string|int, mixed> $context */
    public function resolve(string $id, array $context = []): object
    {
        $class = $this->invokables[$id];

        try {
            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($class);
            $arguments = $this->constructorArguments($reflection, $context);

            if ($this->proxyFactory === null) {
                return $reflection->newInstanceArgs($arguments);
            }

            return match ($this->detectStrategy($class)) {
                CreationStrategy::Proxy => $this->proxyFactory->makeProxy(
                    $class,
                    static fn(object $proxy): object => $reflection->newInstanceArgs($arguments),
                ),
                CreationStrategy::Lazy => $this->proxyFactory->makeLazy(
                    $class,
                    static function (object $entry) use ($reflection, $arguments): void {
                        $reflection->getConstructor()?->invokeArgs($entry, $arguments);
                    },
                ),
                CreationStrategy::Eager => $reflection->newInstanceArgs($arguments),
            };
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    /**
     * Resolves only caller-provided overrides plus native defaults.
     *
     * The explicit invokable fast path deliberately does not autowire optional
     * constructor parameters. This keeps get() on an invokable equivalent to a
     * direct no-argument construction while make(..., $context) can override
     * those defaults by name, position or declared object type.
     *
     * @param ReflectionClass<object> $reflection
     * @param array<string|int, mixed> $context
     * @return array<int, mixed>
     */
    private function constructorArguments(
        ReflectionClass $reflection,
        array $context,
    ): array {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        return $this->contextResolver->resolve(
            $constructor->getParameters(),
            $context,
        );
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if (!$definition instanceof InvokableDefinition) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        InvokableSpecificationValidator::assertValid(
            $definition->value,
            $this->attributeProcessor,
        );
        $this->invokables[$id] = $definition->value;
    }

    public function removeDefinition(string $id): void
    {
        unset($this->invokables[$id]);
    }

    public function supportsDefinition(DefinitionInterface $definition): bool
    {
        return $definition instanceof InvokableDefinition;
    }

    /** @param class-string $class */
    private function detectStrategy(string $class): CreationStrategy
    {
        if (isset($this->strategyCache[$class])) {
            return $this->strategyCache[$class];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $proxyAttribute = $reflection->getAttributes(Proxy::class)[0] ?? null;

        if ($proxyAttribute !== null) {
            $proxy = $proxyAttribute->newInstance();

            if (!$proxy instanceof Proxy || $proxy->class !== null) {
                throw new LogicException(
                    'Class-level #[Proxy] must not specify a proxy class; the marked class is used.',
                );
            }

            return $this->strategyCache[$class] = CreationStrategy::Proxy;
        }

        if ($reflection->getAttributes(Lazy::class) !== []) {
            return $this->strategyCache[$class] = CreationStrategy::Lazy;
        }

        return $this->strategyCache[$class] = CreationStrategy::Eager;
    }
}
