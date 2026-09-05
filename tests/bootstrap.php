<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$packageDirectory = dirname(__DIR__);
$packagesDirectory = dirname($packageDirectory);
$workspaceDirectory = dirname($packagesDirectory);
$localAutoload = $packageDirectory . '/vendor/autoload.php';
$toolchainAutoload = $packagesDirectory . '/var-export/vendor/autoload.php';
$usingLocalAutoload = is_file($localAutoload);
$autoload = $usingLocalAutoload ? $localAutoload : $toolchainAutoload;

if (!is_file($autoload)) {
    throw new RuntimeException('Install DI development dependencies before running its test suite.');
}

/** @var ClassLoader $loader */
$loader = require $autoload;

$loader->setPsr4('Componenta\\DI\\', $packageDirectory . '/src');
$loader->setPsr4('Componenta\\DI\\Tests\\', __DIR__);
require __DIR__ . '/Support/functions.php';

if (!$usingLocalAutoload) {
    /** @var array<string, list<string>> $workspacePsr4 */
    $workspacePsr4 = require $workspaceDirectory . '/vendor/composer/autoload_psr4.php';
    foreach ($workspacePsr4 as $prefix => $paths) {
        $loader->addPsr4($prefix, $paths);
    }

    $loader->setPsr4('Componenta\\Config\\', $packagesDirectory . '/config/src');
    require $packagesDirectory . '/config/src/functions.php';
    require $packageDirectory . '/src/Internal/functions.php';
}
