<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;

function auditDelegatorFirst(string $entry): string
{
    return $entry . ':first';
}

function auditDelegatorSecond(string $entry): string
{
    return $entry . ':second';
}

final class AuditDeferredDelegatorService
{
    public function decorate(string $entry): string
    {
        return $entry . ':deferred';
    }
}

test('a two-string delegator config value remains a list of two delegators', function (): void {
    $container = ContainerBuilder::configure(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                'audit.service' => 'base',
            ],
            ConfigKey::DELEGATORS => [
                'audit.service' => [
                    __NAMESPACE__ . '\\auditDelegatorFirst',
                    __NAMESPACE__ . '\\auditDelegatorSecond',
                ],
            ],
        ],
    ]))->build();

    expect($container->get('audit.service'))->toBe('base:first:second');
});

test('a deferred service-method delegator remains available as a nested list item', function (): void {
    $container = ContainerBuilder::configure(new Config([
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => [
                'audit.service' => 'base',
                'audit.delegator' => new AuditDeferredDelegatorService(),
            ],
            ConfigKey::DELEGATORS => [
                'audit.service' => [
                    ['audit.delegator', 'decorate'],
                ],
            ],
        ],
    ]))->build();

    expect($container->get('audit.service'))->toBe('base:deferred');
});
