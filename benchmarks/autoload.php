<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/**
 * Select DI source before Composer loads optimized classes or autoload files.
 * Dependencies still come from the supplied vendor installation. Generated
 * Composer files are read without modification.
 *
 * @return Closure(string,string):ClassLoader
 */
return static function (string $autoload, string $source): ClassLoader {
    if (realpath($source) === false || realpath($autoload) === false) {
        throw new RuntimeException('The benchmark autoloader and DI source must exist.');
    }
    $canonicalPath = static fn(string $path): string => str_replace('\\', '/', realpath($path) ?: $path);
    $source = $canonicalPath($source);
    $autoload = $canonicalPath($autoload);

    $vendor = dirname($autoload);
    $composer = $vendor . '/composer';
    if (!is_file($composer . '/autoload_psr4.php')) {
        throw new RuntimeException('The benchmark autoloader must belong to a generated Composer vendor directory.');
    }

    $psr4 = require $composer . '/autoload_psr4.php';
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
    $isProduction = static fn(string $name): bool => str_starts_with(strtolower($name), 'componenta\\di\\')
        && !str_starts_with(strtolower($name), 'componenta\\di\\benchmarks\\')
        && !str_starts_with(strtolower($name), 'componenta\\di\\tests\\');

    $assertSources = static function () use ($source, $isProduction, $canonicalPath): array {
        $declarations = 0;
        $functions = 0;
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $name) {
            if (!$isProduction($name)) {
                continue;
            }
            ++$declarations;
            $file = (new ReflectionClass($name))->getFileName();
            if (!is_string($file) || !str_starts_with($canonicalPath($file), $source . '/')) {
                throw new RuntimeException('Benchmark loaded DI declaration from another source: ' . $name);
            }
        }
        foreach (get_defined_functions()['user'] as $name) {
            if (!$isProduction($name)) {
                continue;
            }
            ++$functions;
            $file = (new ReflectionFunction($name))->getFileName();
            if (!is_string($file) || !str_starts_with($canonicalPath($file), $source . '/')) {
                throw new RuntimeException('Benchmark loaded DI function from another source: ' . $name);
            }
        }
        return ['source' => $source, 'checked_declarations' => $declarations, 'checked_functions' => $functions];
    };
    // A late PSR-4 change cannot undo already declared classes/functions.
    $assertSources();

    if (is_file($composer . '/platform_check.php')) {
        require_once $composer . '/platform_check.php';
    }
    require_once $composer . '/ClassLoader.php';
    $loader = new ClassLoader($vendor);
    foreach (require $composer . '/autoload_namespaces.php' as $prefix => $paths) {
        $loader->set($prefix, array_map($remap, $paths));
    }
    foreach ($psr4 as $prefix => $paths) {
        $loader->setPsr4($prefix, array_map($remap, $paths));
    }
    $loader->setPsr4('Componenta\\DI\\', $source . '/');
    $classmap = array_map($remap, require $composer . '/autoload_classmap.php');
    foreach ($classmap as $name => $file) {
        if ($isProduction($name) && !str_starts_with($file, $source . '/')) {
            throw new RuntimeException('Benchmark DI classmap entry was not remapped: ' . $name);
        }
    }
    $loader->addClassMap($classmap);
    $loader->register(true);
    $autoloadFiles = is_file($composer . '/autoload_files.php')
        ? require $composer . '/autoload_files.php'
        : [];
    foreach ($autoloadFiles as $identifier => $file) {
        if (!isset($GLOBALS['__composer_autoload_files'][$identifier])) {
            $GLOBALS['__composer_autoload_files'][$identifier] = true;
            require $remap($file);
        }
    }

    $containerFile = (new ReflectionClass(Componenta\DI\Container::class))->getFileName();
    if (!is_string($containerFile) || $canonicalPath($containerFile) !== $source . '/Container.php') {
        throw new RuntimeException('Benchmark Container source precheck failed.');
    }
    $assertSources();
    register_shutdown_function(static function () use ($assertSources): void {
        fwrite(STDERR, json_encode(['benchmark_source_check' => $assertSources()], JSON_THROW_ON_ERROR) . PHP_EOL);
    });

    return $loader;
};
