<?php

declare(strict_types=1);

namespace ComponentaAudit\CycleGuard;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use ReflectionFunction;
use RuntimeException;

/** Select archived DI source before eager Composer files or optimized class maps. */
function loadSource(string $sourceRoot, string $vendorDirectory, ?string $testDirectory = null): ClassLoader
{
    require_once $vendorDirectory . '/composer/ClassLoader.php';
    $loader = new ClassLoader($vendorDirectory);
    $classMap = require $vendorDirectory . '/composer/autoload_classmap.php';
    foreach ($classMap as $class => $path) {
        if (!str_starts_with($class, 'Componenta\\DI\\')) {
            continue;
        }
        $marker = strrpos($path, '/src/');
        if (str_starts_with($class, 'Componenta\\DI\\Tests\\') || $marker === false) {
            unset($classMap[$class]);
            continue;
        }
        $selected = $sourceRoot . substr($path, $marker);
        if (!is_file($selected)) {
            unset($classMap[$class]);
            continue;
        }
        $classMap[$class] = $selected;
    }
    $loader->addClassMap($classMap);
    foreach (require $vendorDirectory . '/composer/autoload_psr4.php' as $prefix => $directories) {
        if (!str_starts_with($prefix, 'Componenta\\DI\\')) {
            $loader->setPsr4($prefix, $directories);
        }
    }
    $loader->setPsr4('Componenta\\DI\\', $sourceRoot . '/src');
    if ($testDirectory !== null) {
        $loader->setPsr4('Componenta\\DI\\Tests\\', $testDirectory);
    }
    foreach (require $vendorDirectory . '/composer/autoload_namespaces.php' as $prefix => $directories) {
        $loader->set($prefix, $directories);
    }
    $loader->register(true);
    foreach (require $vendorDirectory . '/composer/autoload_files.php' as $identifier => $path) {
        if ($path === dirname($vendorDirectory) . '/src/Internal/functions.php') {
            $path = $sourceRoot . '/src/Internal/functions.php';
        }
        if (!isset($GLOBALS['__composer_autoload_files'][$identifier])) {
            $GLOBALS['__composer_autoload_files'][$identifier] = true;
            require $path;
        }
    }
    return $loader;
}

/** @return array<string, mixed> */
function sourceProvenance(string $sourceRoot): array
{
    $classes = [];
    foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
        if (!str_starts_with($class, 'Componenta\\DI\\') || str_starts_with($class, 'Componenta\\DI\\Tests\\')) {
            continue;
        }
        $path = (new ReflectionClass($class))->getFileName();
        if (!is_string($path) || !str_starts_with($path, $sourceRoot . '/src/')) {
            throw new RuntimeException('Wrong DI declaration source: ' . $class);
        }
        $classes[$class] = $path;
    }
    $functions = [];
    foreach (get_defined_functions()['user'] as $function) {
        if (!str_starts_with(strtolower($function), 'componenta\\di\\internal\\')) {
            continue;
        }
        $path = (new ReflectionFunction($function))->getFileName();
        if ($path !== $sourceRoot . '/src/Internal/functions.php') {
            throw new RuntimeException('Wrong DI function source: ' . $function);
        }
        $functions[$function] = $path;
    }
    if ($functions === []) {
        throw new RuntimeException('Selected DI functions were not loaded.');
    }
    return [
        'source_root' => $sourceRoot,
        'cycle_guard_sha256' => hash_file('sha256', $sourceRoot . '/src/Internal/CycleGuard.php'),
        'classes' => $classes,
        'functions' => $functions,
    ];
}
