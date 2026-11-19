<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Composer\Autoload\ClassLoader;
use Pest\Mutate\Support\StreamWrapper;
use RuntimeException;

final class SubprocessAutoload
{
    public static function load(string $autoload): ClassLoader
    {
        $target = getenv('PEST_MUTATION_TESTING');
        $deferredFiles = [];
        if ($target !== false) {
            // Composer files must wait until the inherited mutation is active.
            $source = realpath($target);
            /** @var array<string, string> $autoloadFiles */
            $autoloadFiles = require dirname($autoload) . '/composer/autoload_files.php';
            $loadedFiles = $GLOBALS['__composer_autoload_files'] ?? [];
            if (!is_array($loadedFiles)) {
                throw new RuntimeException('Unexpected Composer autoload state.');
            }
            foreach ($autoloadFiles as $identifier => $file) {
                if ($source !== false && realpath($file) === $source) {
                    $loadedFiles[$identifier] = true;
                    $deferredFiles[] = $file;
                }
            }
            $GLOBALS['__composer_autoload_files'] = $loadedFiles;
        }

        $loader = require $autoload;
        if (!$loader instanceof ClassLoader) {
            throw new RuntimeException('Expected the selected checkout autoloader.');
        }

        if ($target !== false) {
            // A native PHP child does not inherit its parent's stream wrapper.
            StreamWrapper::start($target, (string) getenv('PEST_MUTATION_FILE'));
            register_shutdown_function(static function (): void {
                StreamWrapper::disable();
            });
            foreach ($deferredFiles as $file) {
                require_once $file;
            }
        }

        return $loader;
    }
}
