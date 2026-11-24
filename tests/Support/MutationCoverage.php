<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Pest\Mutate\Event\Events\TestSuite\StartMutationGeneration;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;
use Pest\Mutate\Mutation;
use Pest\Support\Coverage;
use Pest\TestSuite;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\PHP;

final class MutationCoverage implements StartMutationGenerationSubscriber
{
    /** Pinned Pest 4 generated class selectors; these are not executed line coverage. */
    private const array SUBPROCESS_CONTRACTS = [
        'src/Container.php' => [
            'tests/Runtime/ContainerColdMutationContractTest.php' => 'P\\Tests\\Runtime\\ContainerColdMutationContractTest::',
        ],
        'src/ContainerFactory.php' => [
            'tests/Container/ContainerFactoryCompletionContractTest.php' => 'P\\Tests\\Container\\ContainerFactoryCompletionContractTest::',
        ],
    ];

    /** @var array<string,array<int,true>> */
    private array $coveredLines = [];
    public function __construct(private readonly MutationFallback $fallback) {}

    public function notify(StartMutationGeneration $event): void
    {
        $this->fallback->verifyControl();
        $path = Coverage::getPath();
        if ($path === '') {
            throw new RuntimeException('Pest did not provide a mutation coverage path.');
        }
        $coverage = require $path;
        if (!$coverage instanceof CodeCoverage) {
            throw new RuntimeException('Pest did not produce a valid mutation coverage report.');
        }

        $data = $coverage->getData();
        $lines = $data->lineCoverage();
        // Keep measured coverage separate from worker selectors without retaining every test ID.
        $this->coveredLines = [];
        foreach ($lines as $source => $file) {
            foreach ($file as $line => $tests) {
                if ($tests !== null && $tests !== []) {
                    $this->coveredLines[$source][$line] = true;
                }
            }
        }
        unset($file, $tests);
        $data->setLineCoverage(self::selectTestClasses($lines));
        unset($lines);
        (new PHP())->process($coverage, $path);

        // Coverage is complete; subsequent PHP processes only need to execute assertions.
        putenv('XDEBUG_MODE=off');
        $_SERVER['XDEBUG_MODE'] = 'off';
        $_ENV['XDEBUG_MODE'] = 'off';
    }

    /**
     * Builds worker selectors without changing which source lines were executed.
     * @param array<string,array<int,list<string>|null>> $lines
     * @return array<string,array<int,list<string>|null>>
     */
    public static function selectTestClasses(array $lines): array
    {
        $root = dirname(__DIR__, 2);
        $registeredTests = [];
        foreach (TestSuite::getInstance()->tests->getFilenames() as $filename) {
            $filename = realpath($filename);
            if ($filename !== false) {
                $registeredTests[$filename] = true;
            }
        }
        $additional = [];
        foreach (self::SUBPROCESS_CONTRACTS as $source => $contracts) {
            $source = realpath($root . '/' . $source);
            if ($source === false) {
                continue;
            }
            foreach ($contracts as $filename => $selector) {
                $filename = realpath($root . '/' . $filename);
                if ($filename !== false && isset($registeredTests[$filename])) {
                    $additional[$source][] = $selector;
                }
            }
        }

        foreach ($lines as $source => &$file) {
            foreach ($file as &$tests) {
                if ($tests === null || $tests === []) {
                    continue;
                }
                // Whole classes retain every covering test without exceeding Windows process limits.
                $tests = array_values(array_unique([
                    ...array_map(static function (string $test): string {
                        $separator = strpos($test, '::');
                        return $separator === false ? $test : substr($test, 0, $separator + 2);
                    }, $tests),
                    ...($additional[$source] ?? []),
                ]));
            }
            unset($tests);
        }
        unset($file);
        return $lines;
    }

    public function covers(Mutation $mutation): bool
    {
        foreach (range($mutation->startLine, $mutation->endLine) as $line) {
            if (isset($this->coveredLines[$mutation->file->getRealPath()][$line])) {
                return true;
            }
        }
        return false;
    }
}
