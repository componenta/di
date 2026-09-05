<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\CapabilityPolicy;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Test data builder. Production tests still cross the public ContainerFactory
 * boundary; this class only keeps dependency fixtures readable.
 */
final class ContainerBuilder
{
    /** @var array<string, mixed> */
    protected array $sections = [];

    public function __construct(
        protected Config $config = new Config([], new Environment([])),
    ) {}

    public static function configure(Config $config): self
    {
        return new self($config);
    }

    /** @param array<string, mixed> $dependencies */
    public static function configureWithDependencies(Config $config, array $dependencies): self
    {
        $builder = new self($config);
        $builder->sections = $dependencies;

        return $builder;
    }

    public function build(): Container
    {
        $value = (new ContainerFactory())->create(
            $this->config,
            new DependencyDefinitions($this->sections),
        );

        if (!$value->container instanceof Container) {
            throw new \RuntimeException('DI factory returned an unsupported container implementation.');
        }

        return $value->container;
    }

    public function addFactory(string $id, mixed $factory): self
    {
        $factories = $this->section(ConfigKey::FACTORIES);
        $factories[$id] = $factory;
        $this->sections[ConfigKey::FACTORIES] = $factories;

        return $this;
    }

    public function addDefinition(string $id, DefinitionInterface $definition): self
    {
        return $this->addFactory($id, $definition);
    }

    /** @param array<string, mixed> $factories */
    public function addFactories(array $factories): self
    {
        foreach ($factories as $id => $factory) {
            $this->addFactory($id, $factory);
        }

        return $this;
    }

    public function addInvokable(string $classOrAlias, ?string $class = null): self
    {
        $invokables = $this->section(ConfigKey::INVOKABLES);
        $invokables[$class === null ? count($invokables) : $classOrAlias] = $class ?? $classOrAlias;
        $this->sections[ConfigKey::INVOKABLES] = $invokables;

        return $this;
    }

    /** @param array<int|string, class-string> $invokables */
    public function addInvokables(array $invokables): self
    {
        foreach ($invokables as $key => $class) {
            is_int($key)
                ? $this->addInvokable($class)
                : $this->addInvokable($key, $class);
        }

        return $this;
    }

    public function addAlias(string $alias, string $target): self
    {
        $aliases = $this->section(ConfigKey::ALIASES);
        $aliases[$alias] = $target;
        $this->sections[ConfigKey::ALIASES] = $aliases;

        return $this;
    }

    /** @param array<string, string> $aliases */
    public function addAliases(array $aliases): self
    {
        foreach ($aliases as $alias => $target) {
            $this->addAlias($alias, $target);
        }

        return $this;
    }

    public function addDelegator(string $id, mixed $delegator): self
    {
        $delegators = $this->section(ConfigKey::DELEGATORS);
        $pipeline = $delegators[$id] ?? [];
        if (!is_array($pipeline)) {
            $pipeline = [];
        }
        $pipeline[] = $delegator;
        $delegators[$id] = $pipeline;
        $this->sections[ConfigKey::DELEGATORS] = $delegators;

        return $this;
    }

    /** @param array<string, mixed> $delegators */
    public function addDelegators(array $delegators): self
    {
        $configured = $this->section(ConfigKey::DELEGATORS);
        foreach ($delegators as $id => $items) {
            $configured[$id] = $items;
        }
        $this->sections[ConfigKey::DELEGATORS] = $configured;

        return $this;
    }

    public function addService(string $id, mixed $service): self
    {
        $services = $this->section(ConfigKey::SERVICES);
        $services[$id] = $service;
        $this->sections[ConfigKey::SERVICES] = $services;

        return $this;
    }

    /** @param array<string, mixed> $services */
    public function addServices(array $services): self
    {
        foreach ($services as $id => $service) {
            $this->addService($id, $service);
        }

        return $this;
    }

    public function addParameterResolver(mixed $resolver, int $priority = 0): self
    {
        $resolvers = $this->section(ConfigKey::PARAMETER_RESOLVERS);
        if (array_key_exists($priority, $resolvers)) {
            throw new InvalidConfigurationException(sprintf(
                'Parameter resolver priority %d is already registered.',
                $priority,
            ));
        }

        $resolvers[$priority] = $resolver;
        $this->sections[ConfigKey::PARAMETER_RESOLVERS] = $resolvers;

        return $this;
    }

    public function replaceParameterResolvers(bool $replace = true): self
    {
        $this->sections[ConfigKey::PARAMETER_RESOLVERS_REPLACE] = $replace;

        return $this;
    }

    public function addAttributeDefinition(mixed $definition): self
    {
        $definitions = $this->section(ConfigKey::ATTRIBUTE_DEFINITIONS);
        $definitions[] = $definition;
        $this->sections[ConfigKey::ATTRIBUTE_DEFINITIONS] = $definitions;

        return $this;
    }

    public function replaceAttributeDefinitions(bool $replace = true): self
    {
        $this->sections[ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE] = $replace;

        return $this;
    }

    public function defineAttributeCapability(CapabilityPolicy $policy): self
    {
        $capabilities = $this->section(ConfigKey::ATTRIBUTE_CAPABILITIES);
        $capabilities[] = $policy;
        $this->sections[ConfigKey::ATTRIBUTE_CAPABILITIES] = $capabilities;

        return $this;
    }

    /** @return array<array-key,mixed> */
    private function section(string $key): array
    {
        $value = $this->sections[$key] ?? [];

        return is_array($value) ? $value : [];
    }
}
