<?php

declare(strict_types=1);

use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$report = tempnam(sys_get_temp_dir(), 'di-bench-');
if ($report === false) {
    throw new RuntimeException('Could not allocate the benchmark smoke report.');
}

try {
    $process = proc_open([
        PHP_BINARY,
        $root . '/vendor/bin/phpbench',
        'run',
        '--config=' . $root . '/phpbench.json.dist',
        '--iterations=1',
        '--report=aggregate',
        '--dump-file=' . $report,
    ], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start PHPBench.');
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('PHPBench smoke execution failed with exit code ' . $exitCode);
    }

    $document = new DOMDocument();
    if (!$document->load($report, LIBXML_NONET)) {
        throw new RuntimeException('PHPBench did not produce a valid XML report.');
    }

    $xpath = new DOMXPath($document);
    $subjects = [];
    foreach ($xpath->query('/phpbench/suite/benchmark/subject') as $subject) {
        if (!$subject instanceof DOMElement || !$subject->parentNode instanceof DOMElement) {
            throw new RuntimeException('Invalid benchmark subject in report.');
        }
        $key = $subject->parentNode->getAttribute('class') . '::' . $subject->getAttribute('name');
        if (isset($subjects[$key])) {
            throw new RuntimeException('Duplicate benchmark subject: ' . $key);
        }
        $subjects[$key] = $subject;
    }

    $verified = 0;
    $variants = 0;
    $paths = glob(__DIR__ . '/Suite/*Bench.php');
    if ($paths === false || $paths === []) {
        throw new RuntimeException('No benchmark files found.');
    }
    foreach ($paths as $path) {
        require_once $path;
        $class = new ReflectionClass('Componenta\\DI\\Benchmarks\\Suite\\' . basename($path, '.php'));
        $instance = $class->newInstance();
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->name, 'bench')) {
                continue;
            }
            $key = $class->name . '::' . $method->name;
            $subject = $subjects[$key] ?? throw new RuntimeException('Missing benchmark subject: ' . $key);
            unset($subjects[$key]);

            $revs = ($method->getAttributes(Revs::class)[0] ?? $class->getAttributes(Revs::class)[0] ?? null)
                ?->newInstance()->revs ?? [];
            $warmup = ($method->getAttributes(Warmup::class)[0] ?? $class->getAttributes(Warmup::class)[0] ?? null)
                ?->newInstance()->revs ?? [0];
            if (count($revs) !== 1 || count($warmup) !== 1) {
                throw new RuntimeException('Declare one revolution and warmup count per subject: ' . $key);
            }

            // First-operation and one-shot mutation subjects must never warm up
            // or repeat on the same state, even if their metadata is edited.
            $oneShot = str_starts_with($method->name, 'benchFirst')
                || in_array($method->name, [
                    'benchReplaceShared', 'benchRetargetAlias', 'benchAppendDelegator',
                    'benchAddExternalContainer', 'benchNotifyExternalChanged',
                ], true);
            if ($oneShot && ($revs !== [1] || $warmup !== [0])) {
                throw new RuntimeException('Invalid one-shot benchmark metadata: ' . $key);
            }

            $provider = $method->getAttributes(ParamProviders::class)[0] ?? null;
            $expectedVariants = 1;
            if ($provider !== null) {
                $providers = $provider->newInstance()->providers;
                if (count($providers) !== 1) {
                    throw new RuntimeException('Smoke verification expects one parameter provider: ' . $key);
                }
                $parameters = $instance->{$providers[0]}();
                $expectedVariants = is_array($parameters) ? count($parameters) : iterator_count($parameters);
            }

            $results = $xpath->query('./variant', $subject);
            if ($expectedVariants < 1 || $results->length !== $expectedVariants) {
                throw new RuntimeException('Incorrect number of benchmark variants: ' . $key);
            }
            foreach ($results as $variant) {
                if (!$variant instanceof DOMElement
                    || (int) $variant->getAttribute('revs') !== $revs[0]
                    || (int) $variant->getAttribute('warmup') !== $warmup[0]
                    || $xpath->query('./iteration', $variant)->length !== 1
                    || $xpath->query('./errors/error | ./failures/failure', $variant)->length !== 0
                ) {
                    throw new RuntimeException('Invalid executed benchmark variant: ' . $key);
                }
                ++$variants;
            }
            ++$verified;
        }
    }
    if ($subjects !== [] || $verified === 0) {
        throw new RuntimeException('Unexpected or missing subjects in benchmark report.');
    }

    printf("Verified %d subjects and %d executed variants.\n", $verified, $variants);
} finally {
    unlink($report);
}
