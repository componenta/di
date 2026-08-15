<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\NotFoundException;

it('wraps foreign delegator failures with the public entry id and previous exception', function (): void {
    $boom = new \RuntimeException('boom');
    $container = minimalBuilder()
        ->addService('delegated.service', 'base')
        ->build();
    $container->delegator('delegated.service', static function () use ($boom): never {
        throw $boom;
    });

    try {
        $container->get('delegated.service');
    } catch (DelegatorException $exception) {
        expect($exception->entryId)->toBe('delegated.service')
            ->and($exception->getPrevious())->toBe($boom);

        return;
    }

    self::fail('expected DelegatorException');
});

it('propagates container exceptions thrown by delegators unchanged', function (): void {
    $original = NotFoundException::forService('nested.missing');
    $container = minimalBuilder()
        ->addService('delegated.service', 'base')
        ->build();
    $container->delegator('delegated.service', static function () use ($original): never {
        throw $original;
    });

    try {
        $container->get('delegated.service');
    } catch (\Throwable $caught) {
        expect($caught)->toBe($original)
            ->and($caught)->not->toBeInstanceOf(DelegatorException::class);

        return;
    }

    self::fail('expected the original container exception');
});
