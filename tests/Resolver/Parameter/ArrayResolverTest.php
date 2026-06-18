<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ArrayResolver;

use function Componenta\DI\Tests\Fixture\typedParam;

describe('Parameter\\ArrayResolver', function () {
    it('returns null when nothing is provided', function () {
        $param = typedParam('untyped', 0);

        expect((new ArrayResolver())->resolveParameter($param, []))->toBeNull();
    });

    it('resolves by name, returning [position, value]', function () {
        $param = typedParam('untyped', 1); // position 1, name "b"

        expect((new ArrayResolver())->resolveParameter($param, ['b' => 'hello']))
            ->toBe([1, 'hello']);
    });

    it('resolves by positional index when no name match', function () {
        $param = typedParam('untyped', 1);

        expect((new ArrayResolver())->resolveParameter($param, [0 => 'first', 1 => 'second']))
            ->toBe([1, 'second']);
    });

    it('prefers a by-name match over a by-position one', function () {
        $param = typedParam('untyped', 0); // position 0, name "a"

        expect((new ArrayResolver())->resolveParameter($param, [0 => 'positional', 'a' => 'named']))
            ->toBe([0, 'named']);
    });

    it('preserves null values provided by name', function () {
        $param = typedParam('withNullable', 0); // ?string $name

        expect((new ArrayResolver())->resolveParameter($param, ['name' => null]))
            ->toBe([0, null]);
    });

    it('preserves false/0/empty string values', function (string $method, int $idx, string $key, mixed $value) {
        $param = typedParam($method, $idx);

        expect((new ArrayResolver())->resolveParameter($param, [$key => $value]))
            ->toBe([$idx, $value]);
    })->with([
        'zero int'   => ['primitives', 0, 'count', 0],
        'empty str'  => ['primitives', 1, 'text', ''],
        'false bool' => ['primitives', 2, 'flag', false],
    ]);

    it('throws ResolutionException when a by-name value violates the declared type', function () {
        $param = typedParam('typedString', 0); // string $name

        expect(fn () => (new ArrayResolver())->resolveParameter($param, ['name' => 123]))
            ->toThrow(ResolutionException::class);
    });

    it('throws ResolutionException when a by-position value violates the declared type', function () {
        $param = typedParam('typedString', 0); // string $name

        expect(fn () => (new ArrayResolver())->resolveParameter($param, [0 => 123]))
            ->toThrow(ResolutionException::class);
    });

    it('ignores previously resolved parameters', function () {
        $param = typedParam('untyped', 0);

        expect((new ArrayResolver())->resolveParameter($param, ['a' => 'x'], [0 => 'already']))
            ->toBe([0, 'x']);
    });
});
