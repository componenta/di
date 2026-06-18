<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

/**
 * Loads compiled DI plans from generated PHP sidecars on demand.
 */
final class FilePlanProvider implements IndexedPlanProviderInterface
{
    private const string FORMAT_INDEXED = 'indexed';

    /** @var array{param?: array, prop?: array}|null */
    private ?array $plans = null;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @var array<string, array{param?: array, prop?: array}> */
    private array $loadedFiles = [];

    public function __construct(
        private readonly string $file,
    ) {}

    /**
     * @return array{param?: array, prop?: array}
     */
    public function plans(): array
    {
        if ($this->plans !== null) {
            return $this->plans;
        }

        $manifest = $this->manifest();

        if (($manifest['format'] ?? null) !== self::FORMAT_INDEXED) {
            return $this->plans ?? [];
        }

        $merged = ['param' => [], 'prop' => []];
        $files = [];

        foreach (['param', 'prop'] as $side) {
            $index = $manifest['index'][$side] ?? [];
            if (!is_array($index)) {
                continue;
            }

            foreach ($index as $file) {
                if (is_string($file)) {
                    $files[$file] = true;
                }
            }
        }

        foreach (array_keys($files) as $file) {
            $plans = $this->loadPlanFile($this->resolvePath($file));

            foreach (['param', 'prop'] as $side) {
                foreach (($plans[$side] ?? []) as $class => $plan) {
                    if (is_string($class) && is_array($plan)) {
                        $merged[$side][$class] = $plan;
                    }
                }
            }
        }

        return $this->plans = $merged;
    }

    /**
     * @return array<int, string|array{kind: string, payload: mixed}>|null
     */
    public function parameterPlan(string $class, string $method): ?array
    {
        if ($this->plans !== null) {
            $plan = $this->plans['param'][$class][$method] ?? null;

            return is_array($plan) ? $plan : null;
        }

        $plans = $this->plansForClass($class, 'param');
        $plan = $plans['param'][$class][$method] ?? null;

        return is_array($plan) ? $plan : null;
    }

    public function propertyPlan(string $class, string $property): string|array|null
    {
        if ($this->plans !== null) {
            $plan = $this->plans['prop'][$class][$property] ?? null;

            return is_string($plan) || is_array($plan) ? $plan : null;
        }

        $plans = $this->plansForClass($class, 'prop');

        $plan = $plans['prop'][$class][$property] ?? null;

        return is_string($plan) || is_array($plan) ? $plan : null;
    }

    /**
     * @return array{param?: array, prop?: array}
     */
    private function plansForClass(string $class, string $side): array
    {
        $manifest = $this->manifest();

        if (($manifest['format'] ?? null) !== self::FORMAT_INDEXED) {
            return $this->plans();
        }

        $index = $manifest['index'][$side] ?? [];
        if (!is_array($index)) {
            return [];
        }

        $file = $index[$class] ?? null;
        if (!is_string($file) || $file === '') {
            return [];
        }

        return $this->loadPlanFile($this->resolvePath($file));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $cached = $this->loadPhpFile($this->file);
        if ($cached === null) {
            $this->plans = [];

            return $this->manifest = [];
        }

        if (($cached['format'] ?? null) === self::FORMAT_INDEXED && is_array($cached['index'] ?? null)) {
            return $this->manifest = $cached;
        }

        $plans = $cached[PlanCompiler::CONFIG_KEY] ?? $cached;
        $this->plans = is_array($plans) ? $plans : [];

        return $this->manifest = [];
    }

    /**
     * @return array{param?: array, prop?: array}
     */
    private function loadPlanFile(string $file): array
    {
        if (isset($this->loadedFiles[$file])) {
            return $this->loadedFiles[$file];
        }

        $cached = $this->loadPhpFile($file);
        if ($cached === null) {
            return $this->loadedFiles[$file] = [];
        }

        $plans = $cached[PlanCompiler::CONFIG_KEY] ?? $cached;

        return $this->loadedFiles[$file] = is_array($plans) ? $plans : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPhpFile(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        try {
            $cached = require $file;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($cached)) {
            return null;
        }

        if (array_key_exists('version', $cached)
            && ($cached['version'] ?? null) !== PlanCompiler::CACHE_VERSION
        ) {
            return null;
        }

        return $cached;
    }

    private function resolvePath(string $file): string
    {
        if ($file === ''
            || $file[0] === '/'
            || $file[0] === '\\'
            || (strlen($file) >= 3 && ctype_alpha($file[0]) && $file[1] === ':')
        ) {
            return $file;
        }

        return dirname($this->file) . '/' . $file;
    }
}
