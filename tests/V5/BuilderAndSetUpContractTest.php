<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

final class SetUpDependency {}

#[SetUp('configure', [
    'name' => new ConfigAttribute('name'),
    'dependency' => new EntryId(SetUpDependency::class),
    'flag' => new Env('FEATURE_FLAG', 'fallback'),
    'runtimeFlag' => new EnvironmentEntry('FEATURE_FLAG', 'fallback'),
])]
final class SetUpContractTarget
{
    public string $name = '';
    public ?SetUpDependency $dependency = null;
    public string $flag = '';
    public string $runtimeFlag = '';

    public function configure(
        string $name,
        SetUpDependency $dependency,
        string $flag,
        string $runtimeFlag,
    ): void {
        $this->name = $name;
        $this->dependency = $dependency;
        $this->flag = $flag;
        $this->runtimeFlag = $runtimeFlag;
    }
}

test('SetUp resolves current config v3 and DI value descriptors', function (): void {
    $environment = new Environment(['FEATURE_FLAG' => 'enabled']);
    $container = ContainerBuilder::configure(new Config(
        ['name' => 'configured'],
        $environment,
    ))->build();

    $entry = $container->make(SetUpContractTarget::class);

    expect($entry->name)->toBe('configured')
        ->and($entry->dependency)->toBeInstanceOf(SetUpDependency::class)
        ->and($entry->flag)->toBe('enabled')
        ->and($entry->runtimeFlag)->toBe('enabled')
        ->and($container->get(Environment::class))->toBe($environment);
});

test('dependency normalization rejects factory ids made unreachable by aliases', function (): void {
    expect(fn() => ContainerBuilder::normalizeDependencies([
        ConfigKey::FACTORIES => [
            'service.alias' => static fn(): object => new \stdClass(),
        ],
        ConfigKey::ALIASES => [
            'service.alias' => \stdClass::class,
        ],
    ]))->toThrow(InvalidConfigurationException::class, 'unreachable after canonicalization');
});

test('the internal config alias cannot be replaced or decorated', function (): void {
    expect(fn() => (new ContainerBuilder())->addAlias(ConfigAttribute::KEY, \stdClass::class))
        ->toThrow(InvalidConfigurationException::class, 'protected DI id')
        ->and(fn() => (new ContainerBuilder())->addDelegator(
            ConfigAttribute::KEY,
            static fn(object $entry): object => $entry,
        ))->toThrow(InvalidConfigurationException::class, 'protected DI id');
});
