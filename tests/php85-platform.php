<?php

declare(strict_types=1);

/**
 * Opt-in native acceptance for the DI 6 E1 platform gate.
 *
 * Run with the exact candidate PHP 8.5 binary:
 *     /path/to/php -n tests/php85-platform.php
 *
 * This does not verify official release provenance or tagged engine source.
 * A semantic pass must be combined with those independent E1 checks before
 * setting the supported minimum. Prereleases cannot pass this stable gate.
 */

$fixture = __DIR__ . '/Fixtures/Php85Callbacks/PlatformTraitCallables.php.fixture';
$namespace = 'Componenta\\DI\\PlatformAcceptance\\';
$expected = [
    'self_default' => ['first', 'second'],
    'parent_default' => ['first-parent', 'second-parent'],
    'constant_and_property' => ['first', 'second', 'first', 'second'],
    'private_self_default' => [
        ['value' => 'private-first'],
        ['error' => Error::class, 'message' => 'Call to undefined method ' . $namespace . 'PrivateSelfReference\\Second::who()'],
    ],
    'named_private_default' => [
        ['value' => 'private-first'],
        ['error' => Error::class, 'message' => 'Call to private method ' . $namespace . 'NamedPrivateReference\\First::who() from scope ' . $namespace . 'NamedPrivateReference\\Second'],
    ],
    'named_public_default' => ['public-first', 'public-first', 'public-first'],
    'attribute_self' => ['first', 'second'],
    'attribute_inline' => ['first', 'second'],
    'attribute_parent' => ['first-parent', 'second-parent'],
    'attribute_named_private' => [
        ['value' => 'private-first'],
        ['error' => Error::class, 'message' => 'Call to private method ' . $namespace . 'Attributes\\PrivateFirst::who() from scope ' . $namespace . 'Attributes\\PrivateSecond'],
    ],
];

$stablePhp85 = preg_match('/\A8\.5\.\d+\z/', PHP_VERSION) === 1;
$result = [
    'schema_version' => 1,
    'runtime' => ['binary' => PHP_BINARY, 'version' => PHP_VERSION, 'stable_php85' => $stablePhp85],
    'fixture_sha256' => hash_file('sha256', $fixture),
    'fixture_exit_code' => null,
    'semantic_acceptance_passed' => false,
    'stable_runtime_acceptance_passed' => false,
    'passed_cases' => 0,
    'total_cases' => count($expected),
    'cases' => [],
    'release_provenance_verified' => false,
    'tagged_source_verified' => false,
];

// The standalone gate is also executed with unsupported PHP binaries.
// @phpstan-ignore smaller.alwaysFalse (Checks the candidate runtime before Composer's platform gate.)
if (PHP_VERSION_ID < 80500) {
    $result['error'] = 'Native first-class callable attribute expressions require PHP 8.5.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(2);
}

// File descriptors keep either stream from blocking the child on a full pipe.
$output = tmpfile();
$errors = tmpfile();
if ($output === false || $errors === false) {
    throw new RuntimeException('Cannot capture output from the native platform fixture.');
}
$process = proc_open([PHP_BINARY, '-n', $fixture], [1 => $output, 2 => $errors], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start the native platform fixture.');
}

$deadline = hrtime(true) + 10_000_000_000;
$timedOut = false;
while (proc_get_status($process)['running']) {
    if (hrtime(true) >= $deadline) {
        $timedOut = true;
        proc_terminate($process, 9);
        break;
    }
    usleep(10_000);
}
$status = proc_close($process);
$result['fixture_exit_code'] = $status;

rewind($output);
rewind($errors);
$stdout = stream_get_contents($output);
$stderr = stream_get_contents($errors);
fclose($output);
fclose($errors);

if ($timedOut || $status !== 0 || $stderr !== '') {
    $result['error'] = $timedOut
        ? 'The native platform fixture timed out.'
        : 'The native platform fixture did not complete cleanly.';
    $result['stdout'] = $stdout;
    $result['stderr'] = $stderr;
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(1);
}


$actual = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($actual)) {
    throw new RuntimeException('The native platform fixture must return a JSON object.');
}

$passed = 0;
foreach ($expected as $name => $value) {
    $matches = array_key_exists($name, $actual) && $actual[$name] === $value;
    $result['cases'][$name] = ['passed' => $matches, 'expected' => $value, 'actual' => $actual[$name] ?? null];
    $passed += (int) $matches;
}

$result['semantic_acceptance_passed'] = $passed === count($expected) && count($actual) === count($expected);
$result['passed_cases'] = $passed;
$result['stable_runtime_acceptance_passed'] = $stablePhp85 && $result['semantic_acceptance_passed'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($result['stable_runtime_acceptance_passed'] ? 0 : 1);
