<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Env;
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

/** Resolves #[Env] parameters and handles #[Env] properties. */
final class EnvResolver implements ParameterResolverInterface, AttributeHandlerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Env::class);
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $attribute = $target->firstAttribute(Env::class);
        if (!$attribute instanceof Env) {
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
        if (!$attribute instanceof Env || !$target instanceof ReflectionProperty) {
            throw new LogicException('EnvResolver received an unsupported attribute target.');
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

    private function read(Env $attribute, string $fallbackName): mixed
    {
        $config = $this->container->get(Config::class);
        if (!$config instanceof Config || $config->environment === null) {
            throw new LogicException('Environment is unavailable in the application Config.');
        }

        return $config->environment->get(
            $attribute->name ?? EnvNameNormalizer::toEnvName($fallbackName),
            $attribute->default,
        );
    }
}
