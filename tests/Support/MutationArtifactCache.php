<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Support;

use Pest\Mutate\Cache\FileStore;
use Pest\Mutate\Mutation;

/** Allows Pest to regenerate a stale generator entry without losing retry history. */
final class MutationArtifactCache extends FileStore
{
    public function has(string $key): bool
    {
        if (!parent::has($key)) {
            return false;
        }
        $entry = $this->get($key);
        if (is_array($entry)) {
            foreach ($entry as $item) {
                if ($item instanceof Mutation && !self::isAvailable($item)) {
                    $this->delete($key);
                    return false;
                }
            }
        }
        return $entry !== null;
    }

    public static function isAvailable(Mutation $mutation): bool
    {
        $path = $mutation->modifiedSourcePath;
        // The Pest generator names each replacement after its source hash.
        clearstatcache(true, $path);
        return is_file($path) && is_readable($path) && hash_file('xxh3', $path) === basename($path);
    }
}
