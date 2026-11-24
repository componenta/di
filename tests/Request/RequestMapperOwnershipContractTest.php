<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestMapperOwnershipContract;

use Componenta\Caster\CasterNotFoundException;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;

test('request mapper sources and renames own their configured array slots', function (bool $editCopy): void {
    $source = RequestDataSource::Query;
    $target = 'renamed';
    $mapper = new MapRequest(sources: [&$source], map: ['input' => &$target]);
    $request = new ServerRequest('GET', '/')
        ->withQueryParams(['input' => 'query'])
        ->withCookieParams(['input' => 'cookie']);

    if ($editCopy) {
        $sources = $mapper->sources;
        $map = $mapper->map;
        $sources[0] = RequestDataSource::Cookies;
        $map['input'] = 'local';
    } else {
        $source = RequestDataSource::Cookies;
        $target = 'local';
    }

    expect($mapper->transform($mapper->extract($request)))->toBe(['renamed' => 'query'])
        ->and($source)->toBe($editCopy ? RequestDataSource::Query : RequestDataSource::Cookies)
        ->and($target)->toBe($editCopy ? 'renamed' : 'local');
})->with(['caller references' => false, 'property copies' => true]);

test('request defaults isolate aliases between calls while preserving the local graph and object identity', function (): void {
    $object = new \stdClass();
    $branch = ['label' => 'configured', 'object' => $object];
    $branch['next'] = &$branch;
    $mapper = new MapRequest(defaults: [
        'primary' => ['option' => &$branch],
        'mirror' => ['option' => &$branch],
    ]);

    $branch['label'] = 'caller label';
    $first = $mapper->transform([]);
    Assert::assertIsArray($first['primary']);
    Assert::assertIsArray($first['primary']['option']);
    Assert::assertIsArray($first['primary']['option']['next']);
    Assert::assertIsArray($first['mirror']);
    Assert::assertIsArray($first['mirror']['option']);
    expect($first['primary']['option']['label'])->toBe('configured');

    $first['primary']['option']['next']['label'] = 'first result';
    $second = $mapper->transform([]);

    Assert::assertIsArray($second['primary']);
    Assert::assertIsArray($second['primary']['option']);
    expect($first['mirror']['option']['label'])->toBe('first result')
        ->and($second['primary']['option']['label'])->toBe('configured')
        ->and($second['primary']['option']['object'])->toBe($object)
        ->and($branch['label'])->toBe('caller label');
});

test('the selected ordering owns an independent copy of its configured arrays', function (): void {
    $columns = ['name' => 'ASC'];
    $mapper = new MapRequest(sortMap: ['name' => ['columns' => &$columns]]);

    $columns['name'] = 'caller order';
    $first = $mapper->transform(['sort' => 'name']);
    Assert::assertIsArray($first['orderBy']);
    Assert::assertIsArray($first['orderBy']['columns']);
    expect($first['orderBy']['columns']['name'])->toBe('ASC');

    $first['orderBy']['columns']['name'] = 'first result';

    expect($mapper->transform(['sort' => 'name']))->toBe(['orderBy' => ['columns' => ['name' => 'ASC']]])
        ->and($columns)->toBe(['name' => 'caller order']);
});

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

test('standalone transformation preserves the append state of an empty array', function (int $removedKey, int $nextKey): void {
    $data = [$removedKey => 'removed'];
    unset($data[$removedKey]);
    $mapper = new MapRequest(cast: ['optional' => 'trim']);
    $mapper->provider = new TestCasterProvider();

    $result = $mapper->transform($data);
    $result[] = 'next';
    $data[] = 'next';

    expect($result)->toBe([$nextKey => 'next'])->and($result)->toBe($data);
})->with([
    'positive' => [20, 21],
    'negative' => [-3, -2],
    'final integer key' => [PHP_INT_MAX, PHP_INT_MAX],
]);
