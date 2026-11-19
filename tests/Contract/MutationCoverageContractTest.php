<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Tests\Support\MutationCoverage;
use Pest\TestSuite;
use RuntimeException;

test('mutation selectors include registered cold contracts without inventing covered lines', function (): void {
    $root = dirname(__DIR__, 2);
    $container = realpath($root . '/src/Container.php');
    $other = realpath($root . '/src/CallableExecutor.php');
    $cold = realpath($root . '/tests/Contract/ContainerColdMutationContractTest.php');
    if ($container === false || $other === false || $cold === false) {
        throw new RuntimeException('Cannot locate mutation selection contract files.');
    }
    $class = 'P\\Tests\\Contract\\RuntimeMutationContractTest::';
    $coldClass = 'P\\Tests\\Contract\\ContainerColdMutationContractTest::';
    // These are policy inputs, not a report of measured source execution.
    $lines = [
        $container => [10 => [$class . 'first', $class . 'second'], 11 => [], 12 => null],
        $other => [10 => [$class . 'first'], 11 => [], 12 => null],
    ];
    $original = $lines;
    $expected = [$class];
    $registered = array_map(realpath(...), TestSuite::getInstance()->tests->getFilenames());
    if (in_array($cold, $registered, true)) {
        $expected[] = $coldClass;
    }

    $selected = MutationCoverage::selectTestClasses($lines);

    expect($selected)->toBe([
        $container => [10 => $expected, 11 => [], 12 => null],
        $other => [10 => [$class], 11 => [], 12 => null],
    ])->and($lines)->toBe($original);

    expect(MutationCoverage::selectTestClasses([
        $container => [11 => [], 12 => null],
    ]))->toBe([$container => [11 => [], 12 => null]])
        ->and(MutationCoverage::selectTestClasses([$other => [10 => [$class . 'first']]]))
        ->toBe([$other => [10 => [$class]]]);
});
