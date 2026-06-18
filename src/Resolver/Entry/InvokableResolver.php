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
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use Throwable;

/**
 * Resolves container entries as invokable classes (direct instantiation).
 *
 * Invokable classes have no-arg constructors. Classes without lazy markers
 * are built eagerly. The {@see Lazy} attribute opts into a PHP 8.4 lazy
 * object (ghost). The {@see Proxy} attribute opts into a virtual proxy.
 */
class InvokableResolver implements DefinitionAwareResolverInterface
{
    /** @var array<string, class-string> id -> class-string to instantiate. */
    private array $invokables = [];

    /** @var array<class-string, Strategy> */
    private array $strategyCache = [];

    /**
     * @param list<class-string> $invokables List of class names to register.
     *                                       Each class is registered under its
     *                                       own FQN as the entry id.
     */
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

    /**
     * Resolves an entry by direct instantiation of the mapped class.
     *
     * @throws ResolutionException If instantiation fails.
     */
    public function resolve(string $id, array $context = []): object
    {
        $class = $this->invokables[$id];

        try {
            if ($this->proxyFactory === null) {
                return new $class();
            }

            return match ($this->detectStrategy($class)) {
                Strategy::VirtualProxy => $this->proxyFactory->makeProxy(
                    $class,
                    static fn(object $proxy): object => new $class(),
                ),
                Strategy::Lazy => $this->proxyFactory->makeLazy(
                    $class,
                    static function (object $entry) use ($class): void {
                        // Skip __construct() when the class doesn't declare
                        // one - PHP treats it as a non-existent method and
                        // raises "Call to undefined method".
                        if (method_exists($class, '__construct')) {
                            $entry->__construct();
                        }
                    },
                ),
                Strategy::Eager => new $class(),
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

    private function detectStrategy(string $class): Strategy
    {
        if (isset($this->strategyCache[$class])) {
            return $this->strategyCache[$class];
        }

        $rc = new ReflectionClass($class);

        if ($rc->getAttributes(Proxy::class) !== []) {
            return $this->strategyCache[$class] = Strategy::VirtualProxy;
        }

        if ($rc->getAttributes(Lazy::class) !== []) {
            return $this->strategyCache[$class] = Strategy::Lazy;
        }

        return $this->strategyCache[$class] = Strategy::Eager;
    }
}
