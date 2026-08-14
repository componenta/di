<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;

it('keeps using the validation provider resolved on first use', function () {
    $first = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return null;
        }
    };
    $second = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            throw new RuntimeException('a later container replacement must not change the resolved provider');
        }
    };
    $container = new class ($first) implements ContainerInterface {
        public function __construct(public ValidationProviderInterface $provider) {}

        public function get(string $id): mixed
        {
            return $this->provider;
        }

        public function has(string $id): bool
        {
            return $id === ValidationProviderInterface::class;
        }
    };
    $provider = new LazyValidationProvider($container);

    expect($provider->provide('FirstDto'))->toBeNull();
    $container->provider = $second;

    expect($provider->provide('SecondDto'))->toBeNull();
});
