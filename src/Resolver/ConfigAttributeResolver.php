<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Componenta\DI\Attribute\Config;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionProperty;
use Reflector;
use Throwable;

/** Resolves #[Config] parameters and handles #[Config] properties. */
final class ConfigAttributeResolver implements
    ParameterResolverInterface,
    AttributeHandlerInterface
{
    public AttributePhase $phase {
        get => AttributePhase::AfterInstantiation;
    }

    public int $priority {
        get => 100;
    }

    private readonly ConfigValueExtractor $extractor;

    public function __construct(
        private readonly ContainerInterface $container,
        ?ConfigValueExtractor $extractor = null,
    ) {
        $this->extractor = $extractor ?? new ConfigValueExtractor();
    }

    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(Config::class);
    }

    public function supportsAttribute(string $attributeClass, Reflector $target): bool
    {
        return $target instanceof ReflectionProperty
            && is_a($attributeClass, Config::class, true);
    }

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        if (!$attribute instanceof Config || !$target instanceof ReflectionProperty) {
            throw new LogicException('ConfigAttributeResolver received an unsupported attribute target.');
        }

        if (!$context->claimProperty($target)) {
            return;
        }

        try {
            $value = $this->readFromConfig($attribute, $target->getName());
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
        $config = $target->firstAttribute(Config::class);
        if ($config === null) {
            return null;
        }

        try {
            return [$target->position, $this->readFromConfig($config, $target->name)];
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forParameter(
                $target->reflection,
                providedParameters: $context->provided,
                resolvedParameters: $context->resolved,
                previous: $e,
            );
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function readFromConfig(Config $config, string $fallbackName): mixed
    {
        return $this->extractor->extract(
            $this->container->get(Config::KEY),
            $config,
            $fallbackName,
        );
    }

}
