<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Tests\Fixture\FakeServerRequest as ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

describe('Resolver\\Parameter\\Request\\RequestParameter', function () {
    it('KEY is the ServerRequestInterface FQN so provided-params carry the contract identity', function () {
        expect(RequestParameter::KEY)->toBe(ServerRequestInterface::class);
    });

    describe('has()', function () {
        it('returns false for an empty params array', function () {
            expect(RequestParameter::has([]))->toBeFalse();
        });

        it('returns false when the KEY entry is not a ServerRequestInterface', function () {
            expect(RequestParameter::has([RequestParameter::KEY => 'not-a-request']))->toBeFalse();
        });

        it('returns true when the KEY entry is a ServerRequestInterface', function () {
            $request = new ServerRequest('GET', '/');

            expect(RequestParameter::has([RequestParameter::KEY => $request]))->toBeTrue();
        });
    });

    describe('get()', function () {
        it('returns null when the request is absent or invalid', function () {
            expect(RequestParameter::get([]))->toBeNull()
                ->and(RequestParameter::get([RequestParameter::KEY => 'bad']))->toBeNull();
        });

        it('returns the registered request instance', function () {
            $request = new ServerRequest('GET', '/');

            expect(RequestParameter::get([RequestParameter::KEY => $request]))->toBe($request);
        });
    });

    describe('with()', function () {
        it('returns a new array with the request set under the KEY', function () {
            $request = new ServerRequest('GET', '/');

            $result = RequestParameter::with(['existing' => 1], $request);

            expect($result[RequestParameter::KEY])->toBe($request)
                ->and($result['existing'])->toBe(1);
        });

        it('overwrites an existing request at the KEY', function () {
            $first = new ServerRequest('GET', '/');
            $second = new ServerRequest('POST', '/');

            $result = RequestParameter::with(
                RequestParameter::with([], $first),
                $second,
            );

            expect(RequestParameter::get($result))->toBe($second);
        });
    });
});
