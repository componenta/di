<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/**
 * Isolated source adapter. Dependencies come from COMPONENTA_DI_BENCH_AUTOLOAD;
 * all production DI classes and autoloaded DI functions come from
 * COMPONENTA_DI_BENCH_SOURCE. No generated Composer file is modified.
 */

$autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
$source = getenv('COMPONENTA_DI_BENCH_SOURCE');
if (!is_string($autoload) || !is_file($autoload)
    || !is_string($source) || !is_dir($source)
) {
    throw new RuntimeException('Set COMPONENTA_DI_BENCH_AUTOLOAD and COMPONENTA_DI_BENCH_SOURCE.');
}
$canonicalPath = static fn(string $path): string => str_replace(DIRECTORY_SEPARATOR, '/', realpath($path) ?: $path);
$autoload = $canonicalPath($autoload);
$source = $canonicalPath($source);
$vendor = dirname($autoload);
$psr4 = require $vendor . '/composer/autoload_psr4.php';
$originalSources = array_map(
    $canonicalPath,
    $psr4['Componenta\\DI\\'] ?? [],
);
if ($originalSources === []) {
    throw new RuntimeException('The selected Composer installation has no Componenta DI source mapping.');
}
$remap = static function (string $path) use ($originalSources, $source, $canonicalPath): string {
    $path = $canonicalPath($path);
    foreach ($originalSources as $original) {
        if ($path === $original) {
            return $source;
        }
        if (str_starts_with($path, $original . '/')) {
            return $source . '/' . substr($path, strlen($original) + 1);
        }
    }
    return $path;
};

require_once $vendor . '/composer/platform_check.php';
require_once $vendor . '/composer/ClassLoader.php';
$loader = new ClassLoader($vendor);
foreach (require $vendor . '/composer/autoload_namespaces.php' as $prefix => $paths) {
    $loader->set($prefix, array_map($remap, $paths));
}
foreach ($psr4 as $prefix => $paths) {
    $loader->setPsr4($prefix, array_map($remap, $paths));
}
$loader->setPsr4('Componenta\\DI\\', $source . '/');
$classmap = array_map($remap, require $vendor . '/composer/autoload_classmap.php');
foreach ($classmap as $class => $file) {
    if (str_starts_with($class, 'Componenta\\DI\\')
        && !str_starts_with($class, 'Componenta\\DI\\Tests\\')
        && !str_starts_with($file, $source . '/')
    ) {
        throw new RuntimeException('A production DI classmap entry was not remapped: ' . $class);
    }
}
$loader->addClassMap($classmap);
$loader->register(true);
foreach (require $vendor . '/composer/autoload_files.php' as $identifier => $file) {
    if (!isset($GLOBALS['__composer_autoload_files'][$identifier])) {
        $GLOBALS['__composer_autoload_files'][$identifier] = true;
        require $remap($file);
    }
}
$containerFile = (new ReflectionClass(Componenta\DI\Container::class))->getFileName();
$functionFile = (new ReflectionFunction('Componenta\\DI\\Internal\\is_entry_class_eligible'))->getFileName();
if (!is_string($containerFile) || $canonicalPath($containerFile) !== $source . '/Container.php'
    || !is_string($functionFile) || $canonicalPath($functionFile) !== $source . '/Internal/functions.php'
) {
    throw new RuntimeException('DI class/function source precheck failed.');
}

return ['loader' => $loader, 'source' => $source, 'autoload' => $autoload, 'vendor' => $vendor];
