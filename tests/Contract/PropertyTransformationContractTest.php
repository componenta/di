<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class BooleanPropertyCaster implements CasterInterface
{
    public string $name { get => 'boolean'; }

    public function cast(mixed $value): mixed
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}

final class BooleanPropertySource
{
    #[ConfigAttribute('flag'), Cast('boolean')]
    public bool $flag;
}

test('property sources are transformed before PHP assigns the final property type', function (): void {
    $provider = new class () implements CasterProviderInterface {
        public function provide(string $name): ?CasterInterface
        {
            return $name === 'boolean' ? new BooleanPropertyCaster() : null;
        }
    };
    $container = ContainerBuilder::configure(new Config(['flag' => 'false'], new Environment([])))
        ->addService(CasterProviderInterface::class, $provider)
        ->build();

    expect($container->make(BooleanPropertySource::class)->flag)->toBeFalse();
});

final class TransformedWriteOnlyProperty
{
    /** @var list<string> */
    public array $assigned = [];

    #[ConfigAttribute('label'), Cast('trim')]
    public string $label {
        set(string $value) {
            $this->assigned[] = $value;
        }
    }
}

test('a write-only property hook receives only the final transformed value once', function (): void {
    $container = ContainerBuilder::configure(new Config(['label' => '  ready  '], new Environment([])))
        ->addService(CasterProviderInterface::class, new \Componenta\DI\Tests\Support\TestCasterProvider())
        ->build();

    expect($container->make(TransformedWriteOnlyProperty::class)->assigned)->toBe(['ready']);
});
