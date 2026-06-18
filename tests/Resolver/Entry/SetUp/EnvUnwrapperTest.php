<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\SetUp\EnvUnwrapper;
use Psr\Container\ContainerInterface;

function configContainerForEnv(?Environment $env): ContainerInterface
{
    $config = $env === null ? null : new Config([], $env);

    return new class ($config) implements ContainerInterface {
        public function __construct(private ?Config $config) {}

        public function get(string $id): mixed
        {
            return $id === Config::class && $this->config !== null
                ? $this->config
                : throw new RuntimeException("no $id");
        }

        public function has(string $id): bool
        {
            return $id === Config::class && $this->config !== null;
        }
    };
}

describe('Resolver\\Entry\\SetUp\\EnvUnwrapper', function () {
    describe('supports()', function () {
        it('recognises Env attribute instances', function () {
            $unwrapper = new EnvUnwrapper(configContainerForEnv(null));

            expect($unwrapper->supports(new Env('X')))->toBeTrue();
        });

        it('rejects anything that is not an Env instance', function () {
            $unwrapper = new EnvUnwrapper(configContainerForEnv(null));

            expect($unwrapper->supports('not-an-env'))->toBeFalse()
                ->and($unwrapper->supports(null))->toBeFalse()
                ->and($unwrapper->supports(new stdClass()))->toBeFalse();
        });
    });

    describe('unwrap()', function () {
        it('reads the variable via the explicit name', function () {
            $env = new Environment(['DATABASE_HOST' => 'db.local']);
            $unwrapper = new EnvUnwrapper(configContainerForEnv($env));

            expect($unwrapper->unwrap(new Env('DATABASE_HOST'), 'anything'))->toBe('db.local');
        });

        it('derives the env name from the SetUp key when Env::$name is null', function () {
            $env = new Environment(['DATABASE_HOST' => 'from-key']);
            $unwrapper = new EnvUnwrapper(configContainerForEnv($env));

            expect($unwrapper->unwrap(new Env(), 'databaseHost'))->toBe('from-key');
        });

        it('returns the attribute default when the variable is missing', function () {
            $unwrapper = new EnvUnwrapper(configContainerForEnv(new Environment([])));

            expect($unwrapper->unwrap(new Env('MISSING', default: 'fb'), 'key'))->toBe('fb');
        });

        it('throws ResolutionException when variable is missing and no default is declared', function () {
            $unwrapper = new EnvUnwrapper(configContainerForEnv(new Environment([])));

            expect(fn () => $unwrapper->unwrap(new Env('ABSENT'), 'key'))
                ->toThrow(ResolutionException::class, 'ABSENT');
        });

        it('returns the default when Config/Environment is unavailable', function () {
            $unwrapper = new EnvUnwrapper(configContainerForEnv(null));

            expect($unwrapper->unwrap(new Env('X', default: 'fallback'), 'key'))->toBe('fallback');
        });

        it('throws ResolutionException when environment is unavailable and no default is set', function () {
            $unwrapper = new EnvUnwrapper(configContainerForEnv(null));

            expect(fn () => $unwrapper->unwrap(new Env('X'), 'key'))
                ->toThrow(ResolutionException::class);
        });
    });
});
