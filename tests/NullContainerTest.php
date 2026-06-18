<?php

declare(strict_types=1);

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\NullContainer;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

describe('NullContainer', function () {
    it('implements PSR-11 ContainerInterface', function () {
        expect(new NullContainer())->toBeInstanceOf(ContainerInterface::class);
    });

    it('reports every id as absent', function (string $id) {
        expect((new NullContainer())->has($id))->toBeFalse();
    })->with([
        'regular id' => ['service'],
        'class FQCN' => [stdClass::class],
        'empty string' => [''],
    ]);

    it('throws NotFoundException on get() regardless of the id', function () {
        expect(fn () => (new NullContainer())->get('anything'))
            ->toThrow(NotFoundException::class);
    });

    it('produces a PSR-11 compatible NotFoundExceptionInterface', function () {
        try {
            (new NullContainer())->get('svc');
        } catch (NotFoundException $e) {
            expect($e)->toBeInstanceOf(NotFoundExceptionInterface::class);
            return;
        }

        self::fail('NullContainer::get() did not throw');
    });

    it('includes the requested id in the not-found message', function () {
        expect(fn () => (new NullContainer())->get('some.service'))
            ->toThrow(NotFoundException::class, 'some.service');
    });
});
