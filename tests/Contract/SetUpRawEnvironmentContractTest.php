<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SetUpRawEnvironment;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[SetUp('configure', ['value' => new Env('RAW_VALUE')])]
final class UntypedSetUpTarget
{
    public mixed $value = null;

    /** @param mixed $value */
    public function configure($value): void
    {
        $this->value = $value;
    }
}

#[SetUp('configure', ['value' => new Env('RAW_VALUE')])]
final class UnionSetUpTarget
{
    public string|int $value = 0;

    public function configure(string|int $value): void
    {
        $this->value = $value;
    }
}

test('SetUp Env descriptors preserve raw values for untyped and union method parameters', function (bool $union): void {
    $environment = new Environment(['RAW_VALUE' => '008']);
    $container = (new ContainerBuilder(new Config([], $environment)))->build();
    $class = $union ? UnionSetUpTarget::class : UntypedSetUpTarget::class;

    foreach ([1, 2] as $_attempt) {
        $target = $container->make($class);

        expect($target->value)->toBe('008')
            ->and($target->value)->toBe($environment->get('RAW_VALUE'));
    }
})->with(['untyped' => false, 'union' => true]);
