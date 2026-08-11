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
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use Throwable;

/** Resolves registered no-argument classes without reflection autowiring. */
class InvokableResolver implements DefinitionAwareResolverInterface, DefinitionRemovalInterface
{
    /** @var array<string, class-string> */
    private array $invokables = [];

    /** @var array<class-string, CreationStrategy> */
    private array $strategyCache = [];

    /** @param list<class-string> $invokables */
    public function __construct(
        array $invokables = [],
        private readonly ?ProxyFactoryInterface $proxyFactory = null,
    ) {
        foreach ($invokables as $class) {
            InvokableSpecificationValidator::assertValid($class);
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
            if ($this->proxyFactory === null) {
                return new $class();
            }

            return match ($this->detectStrategy($class)) {
                CreationStrategy::Proxy => $this->proxyFactory->makeProxy(
                    $class,
                    static fn(object $proxy): object => new $class(),
                ),
                CreationStrategy::Lazy => $this->proxyFactory->makeLazy(
                    $class,
                    static function (object $entry) use ($class): void {
                        /** @var ReflectionClass<object> $reflection */
                        $reflection = new ReflectionClass($class);
                        $reflection->getConstructor()?->invoke($entry);
                    },
                ),
                CreationStrategy::Eager => new $class(),
            };
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    public function setDefinition(string $id, DefinitionInterface $definition): void
    {
        if (!$definition instanceof InvokableDefinition) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        InvokableSpecificationValidator::assertValid($definition->value);
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
