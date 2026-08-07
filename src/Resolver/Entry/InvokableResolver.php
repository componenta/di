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
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use Throwable;

/** Resolves registered no-argument classes without reflection autowiring. */
class InvokableResolver implements DefinitionAwareResolverInterface
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
            $this->invokables[$class] = $class;
        }
    }

    public function can(string $id): bool
    {
        return isset($this->invokables[$id]);
    }

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
                        if (method_exists($class, '__construct')) {
                            $entry->__construct();
                        }
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
        if (!$this->supportsDefinition($definition)) {
            throw InvalidConfigurationException::forUnsupportedDefinition($definition, self::class);
        }

        $this->invokables[$id] = $definition->value;
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

        $reflection = new ReflectionClass($class);

        if ($reflection->getAttributes(Proxy::class) !== []) {
            return $this->strategyCache[$class] = CreationStrategy::Proxy;
        }

        if ($reflection->getAttributes(Lazy::class) !== []) {
            return $this->strategyCache[$class] = CreationStrategy::Lazy;
        }

        return $this->strategyCache[$class] = CreationStrategy::Eager;
    }
}
