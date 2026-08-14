<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolverFactory;
use Psr\Container\ContainerInterface;

it('creates the request resolver without eagerly resolving optional dependencies', function () {
    $container = new class () implements ContainerInterface {
        public function get(string $id): mixed
        {
            throw new RuntimeException("Unexpected eager lookup: {$id}");
        }

        public function has(string $id): bool
        {
            throw new RuntimeException("Unexpected eager lookup: {$id}");
        }
    };

    expect((new RequestResolverFactory())($container))
        ->toBeInstanceOf(RequestResolver::class);
});
