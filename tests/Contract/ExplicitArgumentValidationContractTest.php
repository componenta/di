<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExplicitArgumentValidation;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Client
{
    public string $label = 'untouched';
    public function __construct(public int $timeout = 30) {}
    public function configure(string $label = 'default'): void
    {
        $this->label = $label;
    }
}
final class NoConstructor {}

test('ClassDefinition rejects unused configured arguments', function (ClassDefinition $definition): void {
    expect(function () use ($definition): void {
        $di = (new ContainerBuilder())->addDefinition('client', $definition)->build();
        $di->get('client');
    })->toThrow(InvalidConfigurationException::class);
})->with([
    'constructor typo' => [ClassDefinition::create(Client::class)->constructor(['timout' => 5])],
    'constructor extra position' => [ClassDefinition::create(Client::class)->constructor([0 => 5, 1 => 10])],
    'method typo' => [ClassDefinition::create(Client::class)->call('configure', ['lable' => 'explicit'])],
    'method extra position' => [ClassDefinition::create(Client::class)->call('configure', [0 => 'explicit', 1 => 'extra'])],
    'no constructor' => [ClassDefinition::create(NoConstructor::class)->constructor(['unused' => 5])],
]);

test('ClassDefinition preserves runtime context and discards overridden configured references', function (): void {
    $definition = ClassDefinition::create(Client::class)
        ->constructor(['timeout' => Definition::reference('missing'), 0 => Definition::reference('also.missing')])
        ->call('configure', ['label' => 'configured']);
    $di = (new ContainerBuilder())->addDefinition('client', $definition)->build();

    $entry = $di->make('client', ['timeout' => 5, 'unrelated' => 'context']);
    expect($entry)->toBeInstanceOf(Client::class);
    if (!$entry instanceof Client) {
        throw new \LogicException('Expected a client.');
    }
    expect([$entry->timeout, $entry->label])->toBe([5, 'configured']);
});
