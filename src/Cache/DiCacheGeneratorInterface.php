<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Exception\InvalidConfigurationException;

/**
 * Writes a DI configuration cache file.
 *
 * Implementations take a plain array describing the DI section of a
 * Componenta configuration (factories, invokables, aliases, delegators,
 * services, compiled DI plans) and serialise it to a PHP file that
 * {@see \Componenta\DI\ContainerBuilder} can `require` on bootstrap to skip
 * runtime discovery and reflection.
 *
 * The contract is intentionally narrow - array in, file out. It does
 * not compute DI plans, scan classes, or merge with the rest of the
 * config; those concerns live in the discovery / compile pipeline.
 *
 * Implementations are responsible for:
 *
 *  - Producing valid PHP that returns the exact array passed in.
 *  - Atomic file writes (a partial file must never be observable by a
 *    concurrent reader).
 *  - Creating intermediate directories on demand.
 *  - Invalidating any opcode cache entry for the target path.
 */
interface DiCacheGeneratorInterface
{
    /**
     * Writes `$config` to `$path` as a PHP file returning the array.
     *
     * @param array<string, mixed> $config DI configuration to serialise.
     *     Typically the contents of `dependencies.*` plus compiled DI
     *     plans, but the format is opaque to the writer.
     * @param string               $path   Absolute destination path.
     *     Intermediate directories are created if missing.
     *
     * @throws InvalidConfigurationException If serialisation or write fails.
     */
    public function generate(array $config, string $path): void;
}
