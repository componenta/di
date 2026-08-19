<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Attribute\Handler;

use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Object\CreationStrategy;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Resolver\TypeHints;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Handles #[Make] and #[Proxy] on classes, parameters and properties. */
final class MakeHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly ProxyFactoryInterface $proxyFactory,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
    ): mixed {
        if (!$attribute instanceof Make && !$attribute instanceof Proxy) {
            throw new LogicException('MakeHandler received an unsupported parameter attribute.');
        }

        $make = self::firstParameterAttribute($target, Make::class);
        $proxy = self::firstParameterAttribute($target, Proxy::class);
        $config = self::configuration(
            $target->name,
            $target->className,
            $make,
            $proxy,
        );

        try {
            return $this->create($config);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                previous: $e,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
            );
        }
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof Proxy && $target instanceof ReflectionClass) {
            if ($context->entry !== null) {
                return;
            }
            if ($attribute->class !== null) {
                throw new LogicException(
                    'Class-level #[Proxy] must not specify a proxy class; the marked class is used.',
                );
            }
            $context->selectStrategy(CreationStrategy::Proxy);
            return;
        }

        if ((!$attribute instanceof Make && !$attribute instanceof Proxy)
            || !$target instanceof ReflectionProperty
        ) {
            throw new LogicException('MakeHandler received an unsupported attribute target.');
        }

        // Proxy is a dual-phase definition. Property injection belongs to the
        // after-instantiation half only.
        if ($context->entry === null) {
            return;
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        $make = $attribute instanceof Make
            ? $attribute
            : self::firstPropertyAttribute($target, Make::class);
        $proxy = $attribute instanceof Proxy
            ? $attribute
            : self::firstPropertyAttribute($target, Proxy::class);

        $config = self::configuration(
            $target->getName(),
            TypeHints::classOf($target->getType(), $target->getDeclaringClass()),
            $make,
            $proxy,
        );

        try {
            $context->writeProperty($target, $this->create($config));
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }

    /**
     * @param class-string|null $typeName
     * @return array{entry:non-empty-string,params:array<string|int,mixed>,proxyClass:class-string<object>|null}
     */
    private static function configuration(
        string $name,
        ?string $typeName,
        ?Make $make,
        ?Proxy $proxy,
    ): array {
        $entry = $make->entry ?? $typeName ?? $name;
        if ($entry === '') {
            throw new LogicException('Make entry must be a non-empty string.');
        }

        return [
            'entry' => $entry,
            'params' => $make->params ?? [],
            'proxyClass' => $proxy === null
                ? null
                : self::resolveProxyClass($entry, $typeName, $proxy),
        ];
    }

    /**
     * @param non-empty-string $entry
     * @param class-string|null $typeName
     * @return class-string<object>
     */
    private static function resolveProxyClass(string $entry, ?string $typeName, Proxy $proxy): string
    {
        $class = $proxy->class;
        if ($class === null && $typeName !== null && class_exists($typeName)) {
            $class = $typeName;
        }
        if ($class === null && class_exists($entry)) {
            $class = $entry;
        }
        if ($class === null) {
            throw new LogicException(sprintf(
                'Virtual proxy entry "%s" is not a concrete class; specify #[Proxy(ConcreteClass::class)].',
                $entry,
            ));
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new LogicException(sprintf('Virtual proxy class "%s" must be concrete and instantiable.', $class));
        }
        if ($typeName !== null
            && (class_exists($typeName) || interface_exists($typeName))
            && !is_a($class, $typeName, true)
        ) {
            throw new LogicException(sprintf(
                'Virtual proxy class "%s" is incompatible with declared type "%s".',
                $class,
                $typeName,
            ));
        }

        /** @var class-string<object> $resolved */
        $resolved = $reflection->getName();
        return $resolved;
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private static function firstParameterAttribute(ParameterTarget $target, string $attributeClass): ?object
    {
        $attribute = $target->firstAttribute($attributeClass);
        return $attribute instanceof $attributeClass ? $attribute : null;
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private static function firstPropertyAttribute(ReflectionProperty $property, string $attributeClass): ?object
    {
        /** @var ReflectionAttribute<T>|null $reflector */
        $reflector = $property->getAttributes($attributeClass, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        return $reflector?->newInstance();
    }

    /** @param array{entry:non-empty-string,params:array<string|int,mixed>,proxyClass:class-string<object>|null} $config */
    private function create(array $config): object
    {
        if ($config['proxyClass'] === null) {
            return $this->factory->make($config['entry'], $config['params']);
        }

        return $this->proxyFactory->makeProxy(
            $config['proxyClass'],
            function (object $_proxy) use ($config): object {
                $backing = $this->factory->make($config['entry'], $config['params']);
                $proxyClass = $config['proxyClass'];
                if (!$backing instanceof $proxyClass) {
                    throw new LogicException(sprintf(
                        'Virtual proxy backing entry "%s" must be an instance of "%s"; got "%s".',
                        $config['entry'],
                        $proxyClass,
                        $backing::class,
                    ));
                }
                return $backing;
            },
        );
    }
}
