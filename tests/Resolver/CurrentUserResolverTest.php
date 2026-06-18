<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Tests\Fixture\CurrentUserTargets;
use Componenta\DI\Tests\Fixture\FakeAdmin;
use Componenta\DI\Tests\Fixture\FakeUser;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function containerWithUser(?object $user): ContainerInterface
{
    $provider = new class ($user) implements CurrentUserProviderInterface {
        public function __construct(private ?object $user) {}

        public function getUser(): ?object { return $this->user; }

        public function setUser(?object $user): void { $this->user = $user; }
    };

    return new class ($provider) implements ContainerInterface {
        public function __construct(private CurrentUserProviderInterface $provider) {}

        public function get(string $id): mixed
        {
            if ($id === CurrentUserProviderInterface::class) {
                return $this->provider;
            }
            throw new RuntimeException("no $id");
        }

        public function has(string $id): bool
        {
            return $id === CurrentUserProviderInterface::class;
        }
    };
}

describe('Resolver\\CurrentUserResolver', function () {
    describe('property resolution', function () {
        it('returns null for an unattributed property', function () {
            $resolver = new CurrentUserResolver(containerWithUser(new FakeUser()));

            expect($resolver->resolveProperty(typedProperty(CurrentUserTargets::class, 'unattributed')))
                ->toBeNull();
        });

        it('injects the authenticated user', function () {
            $user = new FakeUser('Alice');
            $resolver = new CurrentUserResolver(containerWithUser($user));
            $property = typedProperty(CurrentUserTargets::class, 'user');

            expect($resolver->resolveProperty($property))->toBe([$property, $user]);
        });

        it('throws ResolutionException when no user is authenticated and property is not nullable', function () {
            $resolver = new CurrentUserResolver(containerWithUser(null));

            expect(fn () => $resolver->resolveProperty(typedProperty(CurrentUserTargets::class, 'user')))
                ->toThrow(ResolutionException::class, 'current user is required but not authenticated');
        });

        it('returns null when no user is authenticated and property is nullable', function () {
            $resolver = new CurrentUserResolver(containerWithUser(null));
            $property = typedProperty(CurrentUserTargets::class, 'optionalUser');

            expect($resolver->resolveProperty($property))->toBe([$property, null]);
        });

        it('enforces the CurrentUser(type) constraint against the resolved user', function () {
            $wrongType = new FakeUser(); // not a FakeAdmin
            $resolver = new CurrentUserResolver(containerWithUser($wrongType));

            expect(fn () => $resolver->resolveProperty(typedProperty(CurrentUserTargets::class, 'admin')))
                ->toThrow(ResolutionException::class, 'current user must be instance of');
        });

        it('enforces the target\'s declared type', function () {
            $admin = new FakeAdmin();
            $resolver = new CurrentUserResolver(containerWithUser($admin));

            expect($resolver->resolveProperty(typedProperty(CurrentUserTargets::class, 'admin'))[1])
                ->toBe($admin);
        });
    });

    describe('parameter resolution', function () {
        it('returns null for an unattributed parameter', function () {
            $resolver = new CurrentUserResolver(containerWithUser(new FakeUser()));

            expect($resolver->resolveParameter(typedParam('byParameters', 3, CurrentUserTargets::class)))
                ->toBeNull();
        });

        it('injects the user into [position, value]', function () {
            $user = new FakeUser('Bob');
            $resolver = new CurrentUserResolver(containerWithUser($user));

            expect($resolver->resolveParameter(typedParam('byParameters', 0, CurrentUserTargets::class)))
                ->toBe([0, $user]);
        });

        it('returns null for an anonymous request when the parameter is nullable', function () {
            $resolver = new CurrentUserResolver(containerWithUser(null));

            expect($resolver->resolveParameter(typedParam('byParameters', 1, CurrentUserTargets::class)))
                ->toBe([1, null]);
        });

        it('throws ResolutionException for anonymous users on non-nullable parameters', function () {
            $resolver = new CurrentUserResolver(containerWithUser(null));

            expect(fn () => $resolver->resolveParameter(typedParam('byParameters', 0, CurrentUserTargets::class)))
                ->toThrow(ResolutionException::class);
        });

        it('enforces the attribute type constraint on parameters', function () {
            $resolver = new CurrentUserResolver(containerWithUser(new FakeUser()));

            expect(fn () => $resolver->resolveParameter(typedParam('byParameters', 2, CurrentUserTargets::class)))
                ->toThrow(ResolutionException::class);
        });
    });
});
