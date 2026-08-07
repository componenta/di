<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Psr\Container\ContainerInterface;

it('resolves and caches the validation provider only on first use', function () {
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
            ++$this->lookups;

            return $this->validation;
        }

        public function has(string $id): bool
        {
            return $id === ValidationProviderInterface::class;
        }
    };

    $provider = new LazyValidationProvider($container);

    expect($lookups)->toBe(0);

    $provider->provide('FirstDto');
    $provider->provide('SecondDto');

    expect($lookups)->toBe(1);
});
