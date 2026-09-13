<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ConfigComposition;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigOverride;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config;
use Componenta\DI\ContainerFactory;

final readonly class ConfiguredService
{
    /** @var array<int|string, string> */
    public array $tags;

    public function __construct(
        #[Config(new ConfigPath('settings.name'))]
        public string $name,
        #[Config(new ConfigPath('settings.tags'))]
        string ...$tags,
    ) {
        $this->tags = $tags;
    }
}

test('composed overrides provide concrete scalar and variadic values to DI', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['settings' => 'previous'],
        static fn(): array => ['settings' => [
            'name' => ConfigOverride::replace('configured'),
            'tags' => ConfigOverride::replace(['one', 'two']),
            'removed' => ConfigOverride::remove(),
        ]],
    );
    $value = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $service = $value->get(ConfiguredService::class, ConfiguredService::class);

    expect($service->name)->toBe('configured')
        ->and($service->tags)->toBe(['one', 'two'])
        ->and($value->config->has(new ConfigPath('settings.removed')))->toBeFalse();
});
