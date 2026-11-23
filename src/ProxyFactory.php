<?php

declare(strict_types=1);

namespace Componenta\DI;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\ResolutionException;
use ReflectionClass;
use stdClass;
use Throwable;

/** Default {@see ProxyFactoryInterface} implementation backed by PHP native lazy objects. */
final readonly class ProxyFactory implements ProxyFactoryInterface
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(T): void $initializer
     * @return T
     */
    public function makeLazy(string $class, callable $initializer): object
    {
        try {
            // Keep the supplied receiver and method through target autoloading.
            if (is_array($initializer)) {
                $initializer = array_map(static fn(mixed $slot): mixed => $slot, $initializer);
            }
            /** @var ReflectionClass<T> $reflection */
            $reflection = new ReflectionClass($class);
            $this->assertSupportsLazyState($reflection, 'lazy ghost');
            return $reflection->newLazyGhost($initializer);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class, $e);
        }
    }

    /**
     * @template TProxy of object
     * @param class-string<TProxy> $class
     * @param callable(TProxy): object $factory
     * @return TProxy
     */
    public function makeProxy(string $class, callable $factory): object
    {
        try {
            if (is_array($factory)) {
                $factory = array_map(static fn(mixed $slot): mixed => $slot, $factory);
            }
            $reflection = new ReflectionClass($class);
            $this->assertSupportsLazyState($reflection, 'virtual proxy');
            // @phpstan-ignore argument.type (PHP accepts compatible parent backing objects.)
            return $reflection->newLazyProxy($factory);
        } catch (ExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($class, $e);
        }
    }

    /** @param ReflectionClass<object> $reflection */
    private function assertSupportsLazyState(ReflectionClass $reflection, string $strategy): void
    {
        if ($reflection->isInterface() || $reflection->isTrait() || $reflection->isAbstract() || $reflection->isEnum()) {
            return;
        }

        $hasBackedProperty = false;
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            // Native allocation owns errors for unsupported internal ancestry.
            if ($current->isInternal() && $current->getName() !== stdClass::class) {
                return;
            }
            if ($hasBackedProperty) {
                continue;
            }

            // A parent's private properties may be absent from the leaf reflection.
            foreach ($current->getProperties() as $property) {
                if (!$property->isStatic() && !$property->isVirtual()) {
                    $hasBackedProperty = true;
                    break;
                }
            }
        }

        if (!$hasBackedProperty) {
            throw new ResolutionException(
                sprintf(
                    'Cannot create native %s for class "%s": no backed instance properties; use eager construction instead.',
                    $strategy,
                    $reflection->getName(),
                ),
                serviceId: $reflection->getName(),
            );
        }
    }
}
