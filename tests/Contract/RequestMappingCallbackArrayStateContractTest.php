<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestMappingCallbackArrayState;

use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @param array<array-key,mixed> $data
 * @return array<array-key,mixed>
 */
function appendValue(array $data): array
{
    $data[] = 'next';
    return $data;
}

// Runtime Closure constants keep the public API executable on PHP 8.4.
define(__NAMESPACE__ . '\\APPEND_VALUE', appendValue(...));

test('afterMap preserves the next append key of the completed mapping', function (
    ?int $existingKey,
    int $removedKey,
    int $nextKey,
): void {
    $raw = $existingKey === null ? [] : [$existingKey => 'existing'];
    $raw[$removedKey] = 'removed';
    $request = (new ServerRequest('GET', '/'))->withQueryParams($raw);
    $container = (new ContainerBuilder())->build();
    $mapped = $container->call(
        static fn(
            #[MapRequest(sources: [RequestDataSource::Query], exclude: ['20', '-3', PHP_INT_MAX . ''])]
            array $data,
        ): array => $data,
        [ServerRequestInterface::class => $request],
    );
    if (!is_array($mapped)) {
        throw new \LogicException('Expected mapped array data.');
    }
    $expected = $existingKey === null
        ? [$nextKey => 'next']
        : [$existingKey => 'existing', $nextKey => 'next'];

    expect(appendValue($mapped))->toBe($expected)
        ->and($container->call(
            static fn(
                #[MapRequest(sources: [RequestDataSource::Query], exclude: ['20', '-3', PHP_INT_MAX . ''], afterMap: APPEND_VALUE)]
                array $data,
            ): array => $data,
            [ServerRequestInterface::class => $request],
        ))->toBe($expected)
        ->and($request->getQueryParams())->toBe($raw);
})->with([
    'positive nonempty' => [0, 20, 21],
    'positive empty' => [null, 20, 21],
    'negative nonempty' => [-7, -3, -2],
    'negative empty' => [null, -3, -2],
    'final integer key' => [null, PHP_INT_MAX, PHP_INT_MAX],
]);
