<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\EntryIdResolver;
use Componenta\DI\Tests\Fixture\EntryIdTargets;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Fixture\typedParam;
use function Componenta\DI\Tests\Fixture\typedProperty;

function entryIdContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw NotFoundException::forService($id);
            }
            $value = $this->entries[$id];
            if ($value instanceof Throwable) {
                throw $value;
            }
            return $value;
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

describe('Resolver\\EntryIdResolver', function () {
    describe('property resolution', function () {
        it('returns null when the property is not marked with #[EntryId]', function () {
            $resolver = new EntryIdResolver(entryIdContainer([]));

            expect($resolver->resolveProperty(typedProperty(EntryIdTargets::class, 'unattributed')))
                ->toBeNull();
        });

        it('fetches the entry from the container using the attribute value', function () {
            $logger = new stdClass();
            $resolver = new EntryIdResolver(entryIdContainer(['logger.file' => $logger]));
            $property = typedProperty(EntryIdTargets::class, 'logger');

            $result = $resolver->resolveProperty($property);

            expect($result)->toBe([$property, $logger]);
        });

        it('compiles and resolves property entry id payload', function () {
            $logger = new stdClass();
            $resolver = new EntryIdResolver(entryIdContainer(['logger.file' => $logger]));
            $property = typedProperty(EntryIdTargets::class, 'logger');

            $payload = $resolver->compilePayload($property);

            expect($payload)->toBe(['id' => 'logger.file'])
                ->and($resolver->resolvePropertyPlan($property, $payload))->toBe([$property, $logger]);
        });

        it('lets container exceptions propagate unchanged', function () {
            $resolver = new EntryIdResolver(entryIdContainer([])); // logger.file missing

            expect(fn () => $resolver->resolveProperty(typedProperty(EntryIdTargets::class, 'logger')))
                ->toThrow(NotFoundException::class);
        });

        it('wraps foreign exceptions from the container into ResolutionException', function () {
            $boom = new RuntimeException('container boom');
            $resolver = new EntryIdResolver(entryIdContainer(['logger.file' => $boom]));

            try {
                $resolver->resolveProperty(typedProperty(EntryIdTargets::class, 'logger'));
            } catch (ResolutionException $e) {
                expect($e->getPrevious())->toBe($boom);
                return;
            }

            self::fail('expected ResolutionException');
        });
    });

    describe('parameter resolution', function () {
        it('returns null for an unattributed parameter', function () {
            $resolver = new EntryIdResolver(entryIdContainer([]));

            expect($resolver->resolveParameter(typedParam('byParameters', 1, EntryIdTargets::class)))
                ->toBeNull();
        });

        it('fetches the entry into [position, value]', function () {
            $cache = new stdClass();
            $resolver = new EntryIdResolver(entryIdContainer(['cache.redis' => $cache]));

            expect($resolver->resolveParameter(typedParam('byParameters', 0, EntryIdTargets::class)))
                ->toBe([0, $cache]);
        });

        it('compiles and resolves parameter entry id payload', function () {
            $cache = new stdClass();
            $resolver = new EntryIdResolver(entryIdContainer(['cache.redis' => $cache]));
            $parameter = typedParam('byParameters', 0, EntryIdTargets::class);

            $payload = $resolver->compilePayload($parameter);

            expect($payload)->toBe(['id' => 'cache.redis'])
                ->and($resolver->resolveParameterPlan($parameter, $payload))->toBe([0, $cache]);
        });

        it('wraps foreign container errors into ResolutionException at parameter level', function () {
            $boom = new RuntimeException('boom');
            $resolver = new EntryIdResolver(entryIdContainer(['cache.redis' => $boom]));

            try {
                $resolver->resolveParameter(typedParam('byParameters', 0, EntryIdTargets::class));
            } catch (ResolutionException $e) {
                expect($e->getPrevious())->toBe($boom);
                return;
            }

            self::fail('expected ResolutionException');
        });
    });
});
