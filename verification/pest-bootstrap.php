<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$autoload = getenv('COMPONENTA_DI_TEST_AUTOLOAD');
$source = getenv('COMPONENTA_DI_TEST_SOURCE');
$tests = getenv('COMPONENTA_DI_TEST_TESTS');

if (!is_string($autoload) || $autoload === '' || !is_file($autoload)) {
    throw new RuntimeException('COMPONENTA_DI_TEST_AUTOLOAD must point to vendor/autoload.php.');
}

if (!is_string($source) || $source === '' || !is_dir($source)) {
    throw new RuntimeException('COMPONENTA_DI_TEST_SOURCE must point to the assembled src directory.');
}

$loader = require $autoload;
if (!$loader instanceof ClassLoader) {
    throw new RuntimeException('Composer autoloader is unavailable.');
}

$loader->setPsr4('Componenta\\DI\\', rtrim($source, '/\\') . '/');

if (is_string($tests) && $tests !== '' && is_dir($tests)) {
    $loader->setPsr4('Componenta\\DI\\Tests\\', rtrim($tests, '/\\') . '/');
}
