<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Tests\Support\MutationCoverage;
use Pest\TestSuite;
use RuntimeException;

test('mutation selectors include registered cold contracts without inventing covered lines', function (
    string $sourceFile,
    string $contractFile,
    string $coldClass,
): void {
    $root = dirname(__DIR__, 2);
    $source = realpath($root . '/' . $sourceFile);
    $other = realpath($root . '/src/CallableExecutor.php');
    $cold = realpath($root . '/' . $contractFile);
    if ($source === false || $other === false || $cold === false) {
        throw new RuntimeException('Cannot locate mutation selection contract files.');
    }
    $class = 'P\\Tests\\Contract\\RuntimeMutationContractTest::';
    // These are policy inputs, not a report of measured source execution.
    $lines = [
        $source => [10 => [$class . 'first', $class . 'second'], 11 => [], 12 => null],
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
        $source => [10 => $expected, 11 => [], 12 => null],
        $other => [10 => [$class], 11 => [], 12 => null],
    ])->and($lines)->toBe($original);

    expect(MutationCoverage::selectTestClasses([
        $source => [11 => [], 12 => null],
    ]))->toBe([$source => [11 => [], 12 => null]])
        ->and(MutationCoverage::selectTestClasses([$other => [10 => [$class . 'first']]]))
        ->toBe([$other => [10 => [$class]]])
        ->and(MutationCoverage::selectTestClasses([
            $source => [10 => [$coldClass . 'first', $coldClass . 'second']],
        ]))->toBe([$source => [10 => [$coldClass]]]);
})->with([
    'container alias authority' => [
        'src/Container.php',
        'tests/Contract/ContainerColdMutationContractTest.php',
        'P\\Tests\\Contract\\ContainerColdMutationContractTest::',
    ],
    'factory completion' => [
        'src/ContainerFactory.php',
        'tests/Contract/ContainerFactoryCompletionContractTest.php',
        'P\\Tests\\Contract\\ContainerFactoryCompletionContractTest::',
    ],
]);
