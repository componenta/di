<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PreparedCallable;

use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\PreparedCallable;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\container;

test('a prepared call uses supplied native values and does not change subsequent DI calls', function (): void {
    $container = container();
    $first = new ServerRequest('GET', '/first');
    $second = new ServerRequest('GET', '/second');
    $target = static fn(
        #[CurrentRequest] ServerRequestInterface $request,
        string ...$arguments,
    ): array => [$request, $arguments];

    expect($container->call(new PreparedCallable($target), [$first, 'one', 'named' => 'two']))
        ->toBe([$first, ['one', 'named' => 'two']])
        ->and($container->call($target, [ServerRequestInterface::class => $second, 'arguments' => ['normal']]))
        ->toBe([$second, ['normal']])
        ->and($container->call(new PreparedCallable($target), ['request' => $second, 'named' => 'next']))
        ->toBe([$second, ['named' => 'next']]);
});

test('prepared calls preserve omitted defaults and the target exception instance', function (): void {
    $container = container();
    $failure = new \DomainException('Prepared target failed.');
    $target = static function (string $left = 'default', string $right = 'right') use ($failure): string {
        if ($right === 'fail') {
            throw $failure;
        }
        return $left . ':' . $right;
    };
    $prepared = new PreparedCallable($target);

    expect($container->call($prepared, ['right' => 'value']))->toBe('default:value');

    $caught = null;
    try {
        $container->call($prepared, ['right' => 'fail']);
    } catch (\DomainException $actual) {
        $caught = $actual;
    }
    expect($caught)->toBe($failure);
});
