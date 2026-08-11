<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Creates #[Make]/#[Proxy] parameter and property values. */
final class MakeAttributeResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 500;
    }

    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly ProxyFactoryInterface $proxyFactory,
    ) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Make::class) || $target->hasAttribute(Proxy::class);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && (is_a($attributeClass, Make::class, true)
                || is_a($attributeClass, Proxy::class, true));
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if ((!$attribute instanceof Make && !$attribute instanceof Proxy)
            || !$target instanceof ReflectionProperty
        ) {
            throw new LogicException('MakeAttributeResolver received an unsupported attribute target.');
        }

        // #[Make] and #[Proxy] are one configuration. Whichever attribute is
        // processed first claims the property; the second invocation becomes
        // a no-op before any factory/proxy work can happen.
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
            name: $target->getName(),
            typeName: TypeHints::classOf($target->getType(), $target->getDeclaringClass()),
            make: $make,
            proxy: $proxy,
        );

        try {
            $value = $this->create($config);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }

        $context->writeProperty($target, $value);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $make = $target->firstAttribute(Make::class);
        $proxy = $target->firstAttribute(Proxy::class);

        if (!$make instanceof Make && !$proxy instanceof Proxy) {
            return null;
        }

        $config = self::configuration(
            name: $target->name,
            typeName: $target->className,
            make: $make instanceof Make ? $make : null,
            proxy: $proxy instanceof Proxy ? $proxy : null,
        );

        try {
            return [$target->position, $this->create($config)];
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

    /**
     * @return array{
     *     entry: string,
     *     params: array<string, mixed>,
     *     proxyClass: class-string|null
     * }
     */
    private static function configuration(
        string $name,
        ?string $typeName,
        ?Make $make,
        ?Proxy $proxy,
    ): array {
        $entry = $make->entry ?? $typeName ?? $name;

        return [
            'entry' => $entry,
            'params' => $make->params ?? [],
            'proxyClass' => $proxy === null
                ? null
                : self::resolveProxyClass($entry, $typeName, $proxy),
        ];
    }

    /** @return class-string */
    private static function resolveProxyClass(
        string $entry,
        ?string $typeName,
        Proxy $proxy,
    ): string {
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

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new LogicException(sprintf(
                'Virtual proxy class "%s" must be concrete and instantiable.',
                $class,
            ));
        }

        return $reflection->getName();
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private static function firstPropertyAttribute(
        ReflectionProperty $property,
        string $attributeClass,
    ): ?object {
        /** @var ReflectionAttribute<T>|null $attribute */
        $attribute = $property->getAttributes(
            $attributeClass,
            ReflectionAttribute::IS_INSTANCEOF,
        )[0] ?? null;

        return $attribute?->newInstance();
    }

    /**
     * @param array{
     *     entry: string,
     *     params: array<string, mixed>,
     *     proxyClass: class-string|null
     * } $config
     */
    private function create(array $config): object
    {
        return $config['proxyClass'] !== null
            ? $this->proxyFactory->makeProxy(
                $config['proxyClass'],
                fn(object $proxy): object => $this->factory->make(
                    $config['entry'],
                    $config['params'],
                ),
            )
            : $this->factory->make($config['entry'], $config['params']);
    }
}
