<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;

it('can resolve validation after a transient first lookup failure', function () {
    $validation = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return null;
        }
    };
    $container = new class ($validation) implements ContainerInterface {
        public bool $fail = true;

        public function __construct(private readonly ValidationProviderInterface $validation) {}

        public function get(string $id): mixed
        {
            if ($this->fail) {
                throw new RuntimeException('transient');
            }

            return $this->validation;
        }

        public function has(string $id): bool
        {
            return $id === ValidationProviderInterface::class;
        }
    };
    $provider = new LazyValidationProvider($container);

    expect(fn() => $provider->provide('FirstDto'))
        ->toThrow(RuntimeException::class, 'transient');

    $container->fail = false;

    expect($provider->provide('SecondDto'))->toBeNull()
        ->and($provider->provide('ThirdDto'))->toBeNull();
});
