<?php

declare(strict_types=1);

use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;

final readonly class DiCacheGraphSharedMarker
{
    public function __construct(public string $value) {}
}

final readonly class DiCacheGraphNestedCallback
{
    /** @param array{callback: Closure} $values */
    public function __construct(public array $values) {}
}

it('preserves repeated object and closure identity across the persistent cache graph', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-graph-' . bin2hex(random_bytes(5)) . '.php';
    $marker = new DiCacheGraphSharedMarker('same-instance');
    $callback = static fn(): string => 'same-closure';
    $nested = new DiCacheGraphNestedCallback(['callback' => $callback]);

    try {
        (new DiCacheGenerator())->generate([
            ConfigKey::SERVICES => [
                'marker.first' => $marker,
                'marker.second' => $marker,
                'nested' => $nested,
                'callback' => $callback,
            ],
        ], $path);

        $cache = require $path;
        $services = $cache[ConfigKey::DEPENDENCIES][ConfigKey::SERVICES];

        expect($services['marker.first'])->toBe($services['marker.second'])
            ->and($services['nested']->values['callback'])->toBe($services['callback'])
            ->and(($services['callback'])())->toBe('same-closure');
    } finally {
        @unlink($path);
    }
});
