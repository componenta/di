<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestTransportValidation;

use Componenta\DI\Attribute\Header;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class RequestConsumer
{
    public int $calls = 0;

    public function __invoke(#[Header('X-Token')] string $token): string
    {
        ++$this->calls;
        return $token;
    }
}

test('a warmed request callable rejects malformed transport without native type errors and recovers', function (mixed $malformed): void {
    $container = (new ContainerBuilder())->build();
    $callback = new RequestConsumer();
    $request = new ServerRequest('GET', '/', ['X-Token' => 'first']);

    expect($container->call($callback, [ServerRequestInterface::class => $request]))->toBe('first');

    $failure = null;
    try {
        $container->call($callback, [ServerRequestInterface::class => $malformed]);
    } catch (ResolutionException $error) {
        $failure = $error;
    }
    expect($failure)->toBeInstanceOf(ResolutionException::class);
    if (!$failure instanceof ResolutionException) {
        throw new \LogicException('Malformed transport must fail before the callable body.');
    }
    expect($failure->getMessage())->toContain('PSR-7 request is required for #[Header]')
        ->and($failure->getPrevious())->toBeNull()
        ->and($callback->calls)->toBe(1)
        ->and($container->call($callback, [ServerRequestInterface::class => $request->withHeader('X-Token', 'second')]))->toBe('second')
        ->and($callback->calls)->toBe(2);
})->with([
    'null transport' => [null],
    'boolean transport' => [false],
    'integer transport' => [17],
    'string transport' => ['request'],
    'array transport' => [[]],
    'unrelated object' => [new \stdClass()],
]);
