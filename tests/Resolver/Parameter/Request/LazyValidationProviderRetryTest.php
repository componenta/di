<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;

it('retries validation provider lookup after a transient failure', function () {
    $lookups = 0;
    $validation = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return null;
        }
    };
    $container = new class ($validation, $lookups) implements ContainerInterface {
        public function __construct(
            private readonly ValidationProviderInterface $validation,
            private int &$lookups,
        ) {}

        public function get(string $id): mixed
        {
            if (++$this->lookups === 1) {
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
        ->toThrow(RuntimeException::class);

    $provider->provide('SecondDto');
    $provider->provide('ThirdDto');

    expect($lookups)->toBe(2);
});
