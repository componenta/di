<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Resolves #[Config] parameters and handles #[Config] properties. */
final class ConfigAttributeResolver implements ParameterResolverInterface, AttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(ConfigAttribute::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(ConfigAttribute::class);
        if (!$attribute instanceof ConfigAttribute) {
            return null;
        }

        try {
            return [$target->position, $this->read($attribute, $target->name)];
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
        if (!$attribute instanceof ConfigAttribute || !$target instanceof ReflectionProperty) {
            throw new LogicException('ConfigAttributeResolver received an unsupported attribute target.');
        }
        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $context->writeProperty($target, $this->read($attribute, $target->getName()));
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forProperty($target, previous: $e);
        }
    }

    private function read(ConfigAttribute $attribute, string $fallbackName): mixed
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config) {
            throw new LogicException(sprintf('Container entry %s must be %s.', Config::class, Config::class));
        }

        return $config->get($attribute->path ?? $fallbackName, $attribute->default);
    }
}
