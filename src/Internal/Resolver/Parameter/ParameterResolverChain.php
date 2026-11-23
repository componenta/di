<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\AttributePlans;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;

/** Owns parameter resolver registration and order. @internal */
final class ParameterResolverChain
{
    /** @var array<int,array{resolver:ParameterResolverInterface,priority:int}> */
    private array $registrations = [];
    /** @var list<ParameterResolverInterface>|null */
    private ?array $ordered = null;

    public private(set) int $revision = 0;
    public private(set) bool $isSealed = false;

    /** @var list<ParameterResolverInterface> */
    public array $resolvers {
        get => $this->ordered ??= $this->sortResolvers();
    }

    public function __construct(private readonly AttributePlans $attributes) {}

    public function add(ParameterResolverInterface $resolver, int $priority): void
    {
        if ($this->isSealed) {
            throw new InvalidConfigurationException(
                'Parameter resolver pipeline is sealed and cannot be changed.',
            );
        }

        $id = spl_object_id($resolver);
        if (isset($this->registrations[$id])) {
            throw new InvalidConfigurationException(sprintf(
                'Parameter resolver %s is already registered.',
                $resolver::class,
            ));
        }

        if ($resolver instanceof AttributeParameterResolver && !ParameterAttributeAccess::usesAttributeRegistry($resolver, $this->attributes)) {
            throw new InvalidConfigurationException('Attribute and parameter resolution must use the same attribute definition registry.');
        }

        $this->registrations[$id] = ['resolver' => $resolver, 'priority' => $priority];
        $this->ordered = null;
        ++$this->revision;
    }

    public function seal(): void
    {
        $this->isSealed = true;
    }

    /** @return list<ParameterResolverInterface> */
    private function sortResolvers(): array
    {
        $registrations = $this->registrations;
        // PHP's stable sort retains registration order among equal priorities.
        usort($registrations, static fn(array $left, array $right): int => $right['priority'] <=> $left['priority']);

        return array_column($registrations, 'resolver');
    }
}
