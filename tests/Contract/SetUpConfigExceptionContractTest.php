<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SetUpConfigException;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\LazyValue;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Container;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use RuntimeException;

final class ConfiguredTargetState
{
    public int $constructors = 0;
    public int $setups = 0;
    public ?Container $container = null;
    public ?NotFoundException $failure = null;
}

#[SetUp('configure', ['ratio' => new ConfigAttribute('lazy.ratio')])]
final class ConfiguredTarget
{
    public float $ratio = 0.0;

    public function __construct(private readonly ConfiguredTargetState $state)
    {
        ++$this->state->constructors;
    }

    public function configure(float $ratio): void
    {
        ++$this->state->setups;
        $this->ratio = $ratio;
    }
}

test('SetUp Config descriptors preserve owned failures and wrap foreign failures before a successful retry', function (
    bool $foreign,
    bool $fresh,
): void {
    $state = new ConfiguredTargetState();
    $foreignFailure = new RuntimeException('configuration callback failed');
    $attempts = 0;
    $value = new LazyValue(static function () use ($state, &$attempts, $foreign, $foreignFailure): float {
        ++$attempts;
        if ($foreign && $attempts === 1) {
            throw $foreignFailure;
        }
        if (!$state->container instanceof Container) {
            throw new LogicException('The configuration callback must run after container construction.');
        }
        try {
            $ratio = $state->container->get('setup.ratio');
        } catch (NotFoundException $error) {
            $state->failure = $error;
            throw $error;
        }
        if (!is_float($ratio)) {
            throw new LogicException('The configured ratio must be a float.');
        }
        return $ratio;
    });
    $container = (new ContainerBuilder(new Config(['lazy.ratio' => $value], new Environment([]))))
        ->addService(ConfiguredTargetState::class, $state)
        ->build();
    $state->container = $container;
    $resolve = static fn(): mixed => $fresh
        ? $container->make(ConfiguredTarget::class)
        : $container->get(ConfiguredTarget::class);
    $failure = null;

    try {
        $resolve();
    } catch (ExceptionInterface $error) {
        $failure = $error;
    }

    expect($attempts)->toBe(1)
        ->and($state->constructors)->toBe(1)
        ->and($state->setups)->toBe(0);
    if ($foreign) {
        if (!$failure instanceof ResolutionException) {
            throw new LogicException('A foreign configuration failure must be normalized.', previous: $failure);
        }
        expect($failure->getPrevious())->toBe($foreignFailure)
            ->and($failure->getMessage())->toContain('Cannot unwrap #[SetUp] param "ratio" (#[Config])');
    } else {
        if (!$failure instanceof NotFoundException) {
            throw new LogicException('The configuration callback must preserve the owned DI failure.', previous: $failure);
        }
        expect($failure)->toBe($state->failure)
            ->and($failure->id)->toBe('setup.ratio')
            ->and($failure->getPrevious())->toBeNull();
    }

    $container->set('setup.ratio', 1.25);
    $recovered = $resolve();
    if (!$recovered instanceof ConfiguredTarget) {
        throw new LogicException('Expected the configured target after resolving its dependency.');
    }
    expect($recovered->ratio)->toBe(1.25)
        ->and($attempts)->toBe(2)
        ->and($state->constructors)->toBe(2)
        ->and($state->setups)->toBe(1);
    if (!$fresh) {
        expect($container->get(ConfiguredTarget::class))->toBe($recovered)
            ->and($state->constructors)->toBe(2)
            ->and($state->setups)->toBe(1);
    }
})->with(['owned' => false, 'foreign' => true])
    ->with(['get' => false, 'make' => true]);
