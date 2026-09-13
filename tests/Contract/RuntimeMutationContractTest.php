<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Container\ContainerInterface;

final readonly class RuntimeLabelDecorator
{
    public function __construct(private string $label) {}

    public function __invoke(string $entry): string
    {
        return $entry . ':' . $this->label;
    }
}

final readonly class RuntimeEntryContainer implements ContainerInterface
{
    /** @param array<string,mixed> $entries */
    public function __construct(private array $entries) {}

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new \LogicException('Unexpected external entry: ' . $id);
        }
        return $this->entries[$id];
    }
}

test('adding a delegator to an already used decorator refreshes its dependent products', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('decorator', new RuntimeLabelDecorator('original'));
    $container->set('product', 'base');
    $container->delegator('product', 'decorator');
    expect($container->get('product'))->toBe('base:original');

    $container->delegator('decorator', static fn(RuntimeLabelDecorator $previous): RuntimeLabelDecorator => new RuntimeLabelDecorator('updated'));
    expect($container->get('product'))->toBe('base:updated')
        ->and($container->get('product'))->toBe('base:updated');
});

test('appending a delegator rebuilds the cached pipeline in registration order', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('product', 'base');
    $container->delegator('product', new RuntimeLabelDecorator('first'));
    expect($container->get('product'))->toBe('base:first');

    $container->delegator('product', new RuntimeLabelDecorator('second'));
    expect($container->get('product'))->toBe('base:first:second');
});

test('external takeover continues past dependencies already owned by an earlier container', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->set('first.decorator', new RuntimeLabelDecorator('local-first'));
    $container->set('second.decorator', new RuntimeLabelDecorator('local-second'));
    $container->set('first.product', 'first');
    $container->set('second.product', 'second');
    $container->delegator('first.product', 'first.decorator');
    $container->delegator('second.product', 'second.decorator');
    expect($container->get('first.product'))->toBe('first:local-first')
        ->and($container->get('second.product'))->toBe('second:local-second');

    $container->addContainer(new RuntimeEntryContainer(['first.decorator' => new RuntimeLabelDecorator('external-first')]));
    expect($container->get('first.product'))->toBe('first:external-first');

    $container->addContainer(new RuntimeEntryContainer([
        'first.decorator' => new RuntimeLabelDecorator('later-first'),
        'second.decorator' => new RuntimeLabelDecorator('external-second'),
    ]));
    expect($container->get('first.product'))->toBe('first:external-first')
        ->and($container->get('second.product'))->toBe('second:external-second');
});

test('aliases to core entries preserve mutation protection', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->alias('public.container', ContainerInterface::class);

    expect(fn() => $container->set('public.container', new \stdClass()))
        ->toThrow(InvalidConfigurationException::class, 'Cannot replace protected DI id "public.container".')
        ->and(fn() => $container->delegator('public.container', static fn(object $entry): object => $entry))
        ->toThrow(InvalidConfigurationException::class, 'Cannot decorate protected DI id "public.container".')
        ->and($container->get('public.container'))->toBe($container);
});
