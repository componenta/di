<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestMapperOwnership;

use Componenta\Caster\CasterNotFoundException;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Tests\Support\TestCasterProvider;

test('request transformation leaves caller reference slots intact while preserving object identity and key order', function (): void {
    $original = '  mapped value  ';
    $object = new \stdClass();
    $data = ['first' => 1, 'value' => &$original, 'object' => $object, 'last' => 2];
    $mapper = new MapRequest(cast: ['value' => 'trim']);
    $mapper->provider = new TestCasterProvider();

    $result = $mapper->transform($data);

    expect($result)->toBe(['first' => 1, 'value' => 'mapped value', 'object' => $object, 'last' => 2])
        ->and($original)->toBe('  mapped value  ')
        ->and($data)->toBe(['first' => 1, 'value' => '  mapped value  ', 'object' => $object, 'last' => 2]);
});

test('failed request transformation does not partially overwrite caller reference slots', function (): void {
    $original = '  unchanged  ';
    $data = ['value' => &$original, 'later' => 'input'];
    $mapper = new MapRequest(cast: ['value' => 'trim', 'later' => 'missing-caster']);
    $mapper->provider = new TestCasterProvider();

    expect(fn() => $mapper->transform($data))->toThrow(CasterNotFoundException::class)
        ->and($original)->toBe('  unchanged  ')
        ->and($data)->toBe(['value' => '  unchanged  ', 'later' => 'input']);
});

test('request sorting replaces only the transformed order without rebinding caller data', function (): void {
    $original = ['created' => 'DESC'];
    $data = ['before' => true, 'orderBy' => &$original, 'sort' => 'name', 'after' => false];
    $mapper = new MapRequest(sortMap: ['name' => ['name' => 'ASC']]);

    $result = $mapper->transform($data);

    expect($result)->toBe(['before' => true, 'orderBy' => ['name' => 'ASC'], 'after' => false])
        ->and($original)->toBe(['created' => 'DESC'])
        ->and($data)->toBe(['before' => true, 'orderBy' => ['created' => 'DESC'], 'sort' => 'name', 'after' => false]);
});
