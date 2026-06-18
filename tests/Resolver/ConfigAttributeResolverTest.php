<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\Config\Config as AppConfig;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\ConfigAttributeResolver;
use Componenta\DI\Resolver\ConfigValueExtractor;
use Componenta\DI\Tests\Fixture\ConfigTargets;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function configContainer(array|AppConfig|null $data): ContainerInterface
{
    return new class ($data) implements ContainerInterface {
        public function __construct(private array|AppConfig|null $data) {}

        public function get(string $id): mixed
        {
            if ($id === Config::KEY && $this->data !== null) {
                return $this->data;
            }
            throw NotFoundException::forService($id);
        }

        public function has(string $id): bool
        {
            return $id === Config::KEY && $this->data !== null;
        }
    };
}

describe('Resolver\\ConfigAttributeResolver', function () {
    describe('property resolution', function () {
        it('returns null for an unattributed property', function () {
            $resolver = new ConfigAttributeResolver(configContainer([]));

            expect($resolver->resolveProperty(typedProperty(ConfigTargets::class, 'unattributed')))
                ->toBeNull();
        });

        it('reads a literal key from a plain array config', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['database_host' => 'localhost']));
            $property = typedProperty(ConfigTargets::class, 'explicitLiteral');

            expect($resolver->resolveProperty($property))->toBe([$property, 'localhost']);
        });

        it('falls back to the property name when the attribute has no path', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['timeout' => '30']));

            expect($resolver->resolveProperty(typedProperty(ConfigTargets::class, 'timeout'))[1])
                ->toBe('30');
        });

        it('uses the attribute default when the key is missing', function () {
            $resolver = new ConfigAttributeResolver(configContainer([]));

            expect($resolver->resolveProperty(typedProperty(ConfigTargets::class, 'withDefault'))[1])
                ->toBe('fallback');
        });

        it('wraps missing-key errors for required properties into ResolutionException', function () {
            $resolver = new ConfigAttributeResolver(configContainer([]));

            expect(fn () => $resolver->resolveProperty(typedProperty(ConfigTargets::class, 'required')))
                ->toThrow(ResolutionException::class);
        });

        it('traverses nested Path values through a plain array config', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['database' => ['host' => 'db.local']]));

            expect($resolver->resolveProperty(typedProperty(ConfigTargets::class, 'nestedPath'))[1])
                ->toBe('db.local');
        });

        it('traverses nested Path values through a Componenta\\Config\\Config source', function () {
            $resolver = new ConfigAttributeResolver(configContainer(new AppConfig(['database' => ['host' => 'from-app-config']])));

            expect($resolver->resolveProperty(typedProperty(ConfigTargets::class, 'nestedPath'))[1])
                ->toBe('from-app-config');
        });

        it('compiles and resolves a nested Path property payload', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['database' => ['host' => 'db.local']]));
            $property = typedProperty(ConfigTargets::class, 'nestedPath');

            $payload = $resolver->compilePayload($property);

            expect($payload)->toBe([
                'mode' => ConfigValueExtractor::MODE_PATH,
                'key' => 'database.host',
                'segments' => ['database', 'host'],
                'hasDefault' => false,
                'default' => null,
            ])->and($resolver->resolvePropertyPlan($property, $payload))->toBe([$property, 'db.local']);
        });
    });

    describe('parameter resolution', function () {
        it('returns null for unattributed parameters', function () {
            $resolver = new ConfigAttributeResolver(configContainer([]));

            expect($resolver->resolveParameter(typedParam('byParameters', 3, ConfigTargets::class)))
                ->toBeNull();
        });

        it('reads a literal key into [position, value]', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['database_host' => 'param-host']));

            expect($resolver->resolveParameter(typedParam('byParameters', 0, ConfigTargets::class)))
                ->toBe([0, 'param-host']);
        });

        it('falls back to the parameter name when no path is set', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['timeout' => 'named']));

            expect($resolver->resolveParameter(typedParam('byParameters', 1, ConfigTargets::class))[1])
                ->toBe('named');
        });

        it('uses the attribute default when the key is missing', function () {
            $resolver = new ConfigAttributeResolver(configContainer([]));

            expect($resolver->resolveParameter(typedParam('byParameters', 2, ConfigTargets::class)))
                ->toBe([2, 42]);
        });

        it('compiles and resolves a defaulted parameter payload', function () {
            $resolver = new ConfigAttributeResolver(configContainer([]));
            $parameter = typedParam('byParameters', 2, ConfigTargets::class);

            $payload = $resolver->compilePayload($parameter);

            expect($payload)->toBe([
                'mode' => ConfigValueExtractor::MODE_LITERAL,
                'key' => 'missing_key',
                'segments' => [],
                'hasDefault' => true,
                'default' => 42,
            ])->and($resolver->resolveParameterPlan($parameter, $payload))->toBe([2, 42]);
        });

        it('falls back to the attribute resolver when the payload is invalid', function () {
            $resolver = new ConfigAttributeResolver(configContainer(['database_host' => 'fallback-host']));
            $parameter = typedParam('byParameters', 0, ConfigTargets::class);

            expect($resolver->resolveParameterPlan($parameter, ['invalid' => true]))
                ->toBe([0, 'fallback-host']);
        });
    });
});

describe('Resolver\\ConfigValueExtractor', function () {
    it('extracts a literal key from a plain array', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config('db_host');

        expect($extractor->extract(['db_host' => 'x'], $attr, 'fallback'))->toBe('x');
    });

    it('uses the fallback name when the attribute path is null', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config();

        expect($extractor->extract(['implicit_key' => 'from-fallback'], $attr, 'implicit_key'))
            ->toBe('from-fallback');
    });

    it('traverses a nested Path through a plain array', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config(new \Componenta\Config\ConfigPath('a.b.c'));

        expect($extractor->extract(['a' => ['b' => ['c' => 'deep']]], $attr, 'fallback'))->toBe('deep');
    });

    it('throws OutOfBoundsException when the literal key is missing and no default is set', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config('absent');

        expect(fn () => $extractor->extract([], $attr, 'absent'))
            ->toThrow(OutOfBoundsException::class);
    });

    it('throws OutOfBoundsException when a nested key is missing and no default is set', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config(new \Componenta\Config\ConfigPath('a.b'));

        expect(fn () => $extractor->extract(['a' => []], $attr, 'fallback'))
            ->toThrow(OutOfBoundsException::class);
    });

    it('throws InvalidArgumentException when traversal hits a non-accessible mid-path value', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config(new \Componenta\Config\ConfigPath('a.b.c'));

        expect(fn () => $extractor->extract(['a' => ['b' => 'string-not-array']], $attr, 'fallback'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('returns the configured default instead of throwing when the key is missing', function () {
        $extractor = new ConfigValueExtractor();
        $attr = new Config('absent', default: 'DEFAULT');

        expect($extractor->extract([], $attr, 'absent'))->toBe('DEFAULT');
    });
});
