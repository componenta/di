<?php

declare(strict_types=1);

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterNotFoundException;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;

function pipelineIntCaster(): CasterInterface
{
    return new class () implements CasterInterface {
        public string $name { get => 'int'; }

        public function cast(mixed $value): mixed
        {
            return (int) $value;
        }
    };
}

function pipelineBoolCaster(): CasterInterface
{
    return new class () implements CasterInterface {
        public string $name { get => 'bool'; }

        public function cast(mixed $value): mixed
        {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
    };
}

function pipelineProvider(array $casters): CasterProviderInterface
{
    return new class ($casters) implements CasterProviderInterface {
        public function __construct(private array $casters) {}

        public function provide(string $name): ?CasterInterface
        {
            return $this->casters[$name] ?? null;
        }
    };
}

function runPipeline(
    array $data = [],
    array $map = [],
    array $defaults = [],
    array $cast = [],
    array $sortMap = [],
    array $exclude = [],
    ?CasterProviderInterface $provider = null,
): array {
    return (new RequestMapperPipeline())->run(
        $data,
        $map,
        $defaults,
        $cast,
        $sortMap,
        $exclude,
        $provider ?? new NullCasterProvider(),
    );
}

describe('Resolver\\Parameter\\Request\\RequestMapperPipeline', function () {
    describe('mapFields step', function () {
        it('returns data unchanged when no mapping rules are set', function () {
            $data = ['a' => 1, 'b' => 2];

            expect(runPipeline(data: $data))->toBe($data);
        });

        it('renames source keys to target keys, dropping the source', function () {
            $result = runPipeline(
                data: ['src_field' => 'value'],
                map: ['src_field' => 'targetField'],
            );

            expect($result)->toBe(['targetField' => 'value']);
        });

        it('throws InvalidArgumentException when a required source key is missing', function () {
            expect(fn () => runPipeline(
                data: [],
                map: ['src' => 'target'],
            ))->toThrow(InvalidArgumentException::class, 'Required key "src" is missing');
        });

        it('silently skips optional source keys marked with "?"', function () {
            $result = runPipeline(
                data: ['other' => 'keep'],
                map: ['?missing' => 'notSet'],
            );

            expect($result)->toBe(['other' => 'keep']);
        });

        it('maps optional keys when they are present', function () {
            $result = runPipeline(
                data: ['slug' => 'abc'],
                map: ['?slug' => 'alias'],
            );

            expect($result)->toBe(['alias' => 'abc']);
        });

        it('keeps the value when a mapping keeps the same key name', function () {
            $result = runPipeline(
                data: ['page' => '2'],
                map: ['?page' => 'page'],
            );

            expect($result)->toBe(['page' => '2']);
        });
    });

    describe('defaults step', function () {
        it('fills keys that are absent after mapping', function () {
            $result = runPipeline(
                data: ['present' => 1],
                defaults: ['absent' => 'filled', 'present' => 'overridden'],
            );

            expect($result)->toBe(['present' => 1, 'absent' => 'filled']);
        });

        it('does not overwrite a null value that is already present', function () {
            $result = runPipeline(
                data: ['k' => null],
                defaults: ['k' => 'default'],
            );

            expect($result)->toBe(['k' => null]);
        });
    });

    describe('cast step', function () {
        it('applies the configured caster to the value under the matching key', function () {
            $result = runPipeline(
                data: ['age' => '42'],
                cast: ['age' => 'int'],
                provider: pipelineProvider(['int' => pipelineIntCaster()]),
            );

            expect($result)->toBe(['age' => 42]);
        });

        it('skips cast when the key is absent from data', function () {
            $result = runPipeline(
                data: [],
                cast: ['missing' => 'int'],
                provider: pipelineProvider(['int' => pipelineIntCaster()]),
            );

            expect($result)->toBe([]);
        });

        it('throws CasterNotFoundException when the caster is not registered', function () {
            expect(fn () => runPipeline(
                data: ['k' => 'v'],
                cast: ['k' => 'unknown'],
                provider: pipelineProvider([]),
            ))->toThrow(CasterNotFoundException::class);
        });

        it('applies casts in the order declared (deterministic for multi-key configs)', function () {
            $result = runPipeline(
                data: ['age' => '5', 'active' => '1'],
                cast: ['age' => 'int', 'active' => 'bool'],
                provider: pipelineProvider([
                    'int'  => pipelineIntCaster(),
                    'bool' => pipelineBoolCaster(),
                ]),
            );

            expect($result)->toBe(['age' => 5, 'active' => true]);
        });
    });

    describe('sortMap step', function () {
        it('replaces sort/order keys with orderBy via the map lookup', function () {
            $result = runPipeline(
                data: ['sort' => 'newest', 'order' => 'desc'],
                sortMap: ['newest' => ['createdAt' => 'desc']],
            );

            expect($result)->toBe(['orderBy' => ['createdAt' => 'desc']]);
        });

        it('sets orderBy to null when the sort alias is not in the map', function () {
            $result = runPipeline(
                data: ['sort' => 'invalid'],
                sortMap: ['valid' => ['x' => 'asc']],
            );

            expect($result)->toBe(['orderBy' => null]);
        });

        it('sets orderBy to null when the sort key is missing from data', function () {
            $result = runPipeline(
                data: [],
                sortMap: ['any' => ['x' => 'asc']],
            );

            expect($result)->toBe(['orderBy' => null]);
        });

        it('always strips the raw sort and order keys from output', function () {
            $result = runPipeline(
                data: ['sort' => 'a', 'order' => 'desc', 'keep' => 'yes'],
                sortMap: ['a' => ['x' => 'asc']],
            );

            expect($result)->toHaveKeys(['orderBy', 'keep'])
                ->and($result)->not->toHaveKey('sort')
                ->and($result)->not->toHaveKey('order');
        });

        it('is a no-op when sortMap is empty', function () {
            $result = runPipeline(
                data: ['sort' => 'x', 'order' => 'desc'],
            );

            expect($result)->toBe(['sort' => 'x', 'order' => 'desc']);
        });
    });

    describe('exclude step', function () {
        it('drops the listed keys from the final array', function () {
            $result = runPipeline(
                data: ['keep' => 1, 'drop' => 2, 'also_drop' => 3],
                exclude: ['drop', 'also_drop'],
            );

            expect($result)->toBe(['keep' => 1]);
        });

        it('ignores exclude entries that are not present', function () {
            $result = runPipeline(
                data: ['k' => 'v'],
                exclude: ['not-in-data'],
            );

            expect($result)->toBe(['k' => 'v']);
        });
    });

    describe('step ordering: mapFields -> cast -> defaults -> sortMap -> exclude', function () {
        it('applies defaults to the mapped target key (not the source)', function () {
            $result = runPipeline(
                data: [],
                map: ['?src' => 'target'],
                defaults: ['target' => 'default-value'],
            );

            expect($result)->toBe(['target' => 'default-value']);
        });

        it('casts against the target key, not the source', function () {
            $result = runPipeline(
                data: ['raw' => '99'],
                map: ['raw' => 'count'],
                cast: ['count' => 'int'],
                provider: pipelineProvider(['int' => pipelineIntCaster()]),
            );

            expect($result)->toBe(['count' => 99]);
        });

        it('exclude runs last, removing entries produced by earlier steps (e.g. defaults)', function () {
            $result = runPipeline(
                data: [],
                defaults: ['extra' => 'x'],
                exclude: ['extra'],
            );

            expect($result)->toBe([]);
        });
    });
});
