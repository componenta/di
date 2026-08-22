<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

final class BindingPreconfiguredBuilder extends ContainerBuilder
{
    public function __construct()
    {
        parent::__construct();

        $this->addService('decorated.service', 'constructor-base');
        $this->addDelegator(
            'decorated.service',
            static fn(string $entry): string => $entry . ':constructor',
        );
    }
}

test('declarative binding sections stay authoritative in subclass builders and cache bootstrap', function (): void {
    $dependencies = [
        ConfigKey::SERVICES => [
            'decorated.service' => 'configured-base',
        ],
        ConfigKey::DELEGATORS => [
            'decorated.service' => [
                static fn(string $entry): string => $entry . ':configured',
            ],
        ],
    ];

    $configured = BindingPreconfiguredBuilder::configureWithDependencies(
        new Config([]),
        $dependencies,
    );
    $cached = BindingPreconfiguredBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => ContainerBuilder::normalizeDependencies($dependencies),
        ],
    );

    foreach ([$configured, $cached] as $builder) {
        $effective = $builder->toArray()[ConfigKey::DEPENDENCIES];

        expect($effective[ConfigKey::SERVICES])->toBe([
            'decorated.service' => 'configured-base',
        ])->and($effective[ConfigKey::DELEGATORS]['decorated.service'])->toHaveCount(1)
            ->and($builder->build()->get('decorated.service'))->toBe('configured-base:configured');
    }
});
