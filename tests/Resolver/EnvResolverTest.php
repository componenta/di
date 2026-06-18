<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\EnvResolver;
use Componenta\DI\Tests\Fixture\EnvTargets;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function envContainer(?Environment $env = null): ContainerInterface
{
    $config = $env === null ? null : new Config([], $env);

    return new class ($config) implements ContainerInterface {
        public function __construct(private ?Config $config) {}

        public function get(string $id): mixed
        {
            if ($id === 'config' && $this->config !== null) {
                return $this->config;
            }
            throw new RuntimeException("no entry: $id");
        }

        public function has(string $id): bool
        {
            return $id === 'config' && $this->config !== null;
        }
    };
}

describe('Resolver\\EnvResolver', function () {
    describe('property resolution', function () {
        it('returns null for a property without the #[Env] attribute', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));

            expect($resolver->resolveProperty(typedProperty(EnvTargets::class, 'unattributed')))
                ->toBeNull();
        });

        it('reads the env var by the explicit name from the attribute', function () {
            $env = new Environment(['DATABASE_HOST' => 'db.local']);
            $resolver = new EnvResolver(envContainer($env));
            $property = typedProperty(EnvTargets::class, 'explicitName');

            $result = $resolver->resolveProperty($property);

            expect($result)->toBe([$property, 'db.local']);
        });

        it('derives the env-var name from the property name when #[Env] has no name', function () {
            $env = new Environment(['DATABASE_HOST' => 'from-implicit']);
            $resolver = new EnvResolver(envContainer($env));
            $property = typedProperty(EnvTargets::class, 'databaseHost');

            expect($resolver->resolveProperty($property)[1])->toBe('from-implicit');
        });

        it('coerces to the declared scalar type', function (string $propName, array $envData, mixed $expected) {
            $env = new Environment($envData);
            $resolver = new EnvResolver(envContainer($env));

            expect($resolver->resolveProperty(typedProperty(EnvTargets::class, $propName))[1])
                ->toBe($expected);
        })->with([
            'int'    => ['port', ['PORT' => '8080'], 8080],
            'float'  => ['rate', ['RATE' => '1.5'], 1.5],
            'bool truthy'  => ['flag', ['FEATURE_FLAG' => 'true'], true],
            'bool falsy'   => ['flag', ['FEATURE_FLAG' => 'false'], false],
        ]);

        it('returns the attribute default when the env var is missing', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));
            $property = typedProperty(EnvTargets::class, 'withIntDefault');

            expect($resolver->resolveProperty($property)[1])->toBe(3600);
        });

        it('throws ResolutionException when the env var is missing and no default was declared', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));

            expect(fn () => $resolver->resolveProperty(typedProperty(EnvTargets::class, 'missingNoDefault')))
                ->toThrow(ResolutionException::class, 'MISSING_VAR');
        });

        it('returns the attribute default when the container has no Config (no Environment)', function () {
            $resolver = new EnvResolver(envContainer(null));
            $property = typedProperty(EnvTargets::class, 'withIntDefault');

            expect($resolver->resolveProperty($property)[1])->toBe(3600);
        });

        it('throws ResolutionException when Config/Environment is absent and no default was declared', function () {
            $resolver = new EnvResolver(envContainer(null));

            expect(fn () => $resolver->resolveProperty(typedProperty(EnvTargets::class, 'missingNoDefault')))
                ->toThrow(ResolutionException::class);
        });

        it('returns the raw value for `mixed` type', function () {
            $env = new Environment(['RAW_MIXED' => '42']);
            $resolver = new EnvResolver(envContainer($env));

            // no typed coercion -> raw string preserved
            expect($resolver->resolveProperty(typedProperty(EnvTargets::class, 'raw'))[1])
                ->toBe('42');
        });

        it('compiles and resolves a property env payload', function () {
            $env = new Environment(['DATABASE_HOST' => 'db.local']);
            $resolver = new EnvResolver(envContainer($env));
            $property = typedProperty(EnvTargets::class, 'explicitName');

            $payload = $resolver->compilePayload($property);

            expect($payload)->toBe([
                'name' => 'DATABASE_HOST',
                'type' => 'string',
                'hasDefault' => false,
                'default' => null,
            ])->and($resolver->resolvePropertyPlan($property, $payload))->toBe([$property, 'db.local']);
        });
    });

    describe('parameter resolution', function () {
        it('returns null for a parameter without #[Env]', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));

            expect($resolver->resolveParameter(typedParam('byParameters', 4, EnvTargets::class)))
                ->toBeNull();
        });

        it('reads the env var by explicit attribute name into [position, value]', function () {
            $env = new Environment(['DATABASE_HOST' => 'from-param']);
            $resolver = new EnvResolver(envContainer($env));
            $param = typedParam('byParameters', 0, EnvTargets::class);

            expect($resolver->resolveParameter($param))->toBe([0, 'from-param']);
        });

        it('derives env-var name from parameter name when attribute has no name', function () {
            $env = new Environment(['DATABASE_HOST' => 'implicit-param']);
            $resolver = new EnvResolver(envContainer($env));

            expect($resolver->resolveParameter(typedParam('byParameters', 1, EnvTargets::class))[1])
                ->toBe('implicit-param');
        });

        it('uses the attribute default when the env var is missing', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));

            expect($resolver->resolveParameter(typedParam('byParameters', 2, EnvTargets::class)))
                ->toBe([2, 3600]);
        });

        it('throws ResolutionException when required env var is missing', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));

            expect(fn () => $resolver->resolveParameter(typedParam('byParameters', 3, EnvTargets::class)))
                ->toThrow(ResolutionException::class);
        });

        it('compiles and resolves an implicit-name parameter payload', function () {
            $env = new Environment(['DATABASE_HOST' => 'implicit-param']);
            $resolver = new EnvResolver(envContainer($env));
            $parameter = typedParam('byParameters', 1, EnvTargets::class);

            $payload = $resolver->compilePayload($parameter);

            expect($payload)->toBe([
                'name' => 'DATABASE_HOST',
                'type' => 'string',
                'hasDefault' => false,
                'default' => null,
            ])->and($resolver->resolveParameterPlan($parameter, $payload))->toBe([1, 'implicit-param']);
        });

        it('compiles and resolves a parameter payload with an attribute default', function () {
            $resolver = new EnvResolver(envContainer(new Environment([])));
            $parameter = typedParam('byParameters', 2, EnvTargets::class);

            $payload = $resolver->compilePayload($parameter);

            expect($payload)->toBe([
                'name' => 'CACHE_TTL',
                'type' => 'int',
                'hasDefault' => true,
                'default' => 3600,
            ])->and($resolver->resolveParameterPlan($parameter, $payload))->toBe([2, 3600]);
        });

        it('falls back to the attribute resolver when the payload is invalid', function () {
            $env = new Environment(['DATABASE_HOST' => 'fallback-param']);
            $resolver = new EnvResolver(envContainer($env));
            $parameter = typedParam('byParameters', 0, EnvTargets::class);

            expect($resolver->resolveParameterPlan($parameter, ['invalid' => true]))
                ->toBe([0, 'fallback-param']);
        });
    });

    describe('EnvNameNormalizer (behavior observed through EnvResolver)', function () {
        it('converts camelCase names to UPPER_SNAKE_CASE', function () {
            expect(\Componenta\DI\Resolver\EnvNameNormalizer::toEnvName('databaseHost'))->toBe('DATABASE_HOST')
                ->and(\Componenta\DI\Resolver\EnvNameNormalizer::toEnvName('appDebug'))->toBe('APP_DEBUG')
                ->and(\Componenta\DI\Resolver\EnvNameNormalizer::toEnvName('plain'))->toBe('PLAIN');
        });
    });
});
