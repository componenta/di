<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableKindCacheBoundaryContract;

use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final class MethodOwner
{
    /** @return array{string,string} */
    public static function read(string $ordinary = 'ordinary default'): array
    {
        return ['ordinary', $ordinary];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return [$method, $arguments];
    }
}

final class StringOwner
{
    /** @return array{string,string} */
    public static function read(string $aliased = 'alias default'): array
    {
        return ['alias', $aliased];
    }
}

// Legal native aliases can contain punctuation resembling a private cache key.
// They still identify ordinary public methods with their own parameter metadata.
define(__NAMESPACE__ . '\\STRING_OWNER', 'method:' . strlen(MethodOwner::class) . ':' . MethodOwner::class);
class_alias(StringOwner::class, STRING_OWNER);

function nativeCallable(mixed $callable): callable
{
    if (!is_callable($callable)) {
        throw new LogicException('The route must retain native callability.');
    }
    return $callable;
}

test('native string aliases cannot reuse method array plans or change magic argument binding', function (
    bool $magic,
    bool $stringFirst,
): void {
    $container = new ContainerBuilder()->build();
    $string = nativeCallable(STRING_OWNER . '::read');
    $array = nativeCallable([MethodOwner::class, $magic ? 'readfunction:' : 'read']);
    $arrayArguments = $magic ? ['positional', 'kept' => 'named'] : ['ordinary' => 'explicit ordinary'];
    $arrayExpected = $magic ? ['readfunction:', $arrayArguments] : ['ordinary', 'explicit ordinary'];
    $stringArguments = ['aliased' => 'explicit alias'];
    $stringExpected = ['alias', 'explicit alias'];
    expect(call_user_func_array($array, $arrayArguments))->toBe($arrayExpected)
        ->and(call_user_func_array($string, $stringArguments))->toBe($stringExpected);
    $routes = [[$array, $arrayArguments, $arrayExpected], [$string, $stringArguments, $stringExpected]];
    if ($stringFirst) {
        $routes = array_reverse($routes);
    }

    foreach ([1, 2] as $_attempt) {
        foreach ($routes as [$callable, $arguments, $expected]) {
            expect($container->call($callable, $arguments))->toBe($expected);
        }
    }
})->with(['declared method' => false, 'magic method' => true])
    ->with(['array first' => false, 'string first' => true]);
