<?php

declare(strict_types=1);

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Psr\Container\ContainerInterface;

it('creates the request resolver without resolving validation services', function () {
    $factory = new class () implements FactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            return new $entry(...$params);
        }
    };
    $casters = new class () implements CasterProviderInterface {
        public function provide(string $name): ?CasterInterface
        {
            return null;
        }
    };
    $validationLookups = 0;
    $dependencyLookups = 0;
    $container = new class ($factory, $casters, $validationLookups, $dependencyLookups) implements ContainerInterface {
        public function __construct(
            private readonly FactoryInterface $factory,
            private readonly CasterProviderInterface $casters,
            private int &$validationLookups,
            private int &$dependencyLookups,
        ) {}

        public function get(string $id): mixed
        {
            if ($id === FactoryInterface::class || $id === CasterProviderInterface::class) {
                ++$this->dependencyLookups;
            }

            return match ($id) {
                FactoryInterface::class => $this->factory,
                CasterProviderInterface::class => $this->casters,
                ValidationProviderInterface::class => $this->validation(),
                default => throw new RuntimeException("Unexpected entry: {$id}"),
            };
        }

        public function has(string $id): bool
        {
            return $id === ValidationProviderInterface::class;
        }

        private function validation(): never
        {
            ++$this->validationLookups;
            throw new RuntimeException('Validation must stay lazy while the resolver is constructed.');
        }
    };

    $resolver = (new RequestResolverFactory())($container);

    expect($resolver)->toBeInstanceOf(RequestResolver::class)
        ->and($validationLookups)->toBe(0)
        ->and($dependencyLookups)->toBe(0);
});
