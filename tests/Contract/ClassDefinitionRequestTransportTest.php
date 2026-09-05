<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConfiguredRequestSourceTarget
{
    public function __construct(
        #[ConfigAttribute('selected.request')]
        public ServerRequestInterface $request,
        #[Header('X-Context')]
        public string $context,
        public string $label = 'default',
    ) {}
}

final readonly class ServiceRequestSourceTarget
{
    public function __construct(
        public ServerRequestInterface $request,
        #[Header('X-Context')]
        public string $context,
        public string $label = 'default',
    ) {}
}

test('ClassDefinition keeps request transport separate from configured and service sources', function (
    bool $attributeSource,
): void {
    $class = $attributeSource ? ConfiguredRequestSourceTarget::class : ServiceRequestSourceTarget::class;
    $selected = new ServerRequest('GET', '/selected');
    $current = new ServerRequest('GET', '/current', ['X-Context' => 'current-context']);
    $container = (new ContainerFactory())->create(
        new Config(['selected.request' => $selected], new Environment([])),
        new DependencyDefinitions([
            'services' => [ServerRequestInterface::class => $selected],
            'factories' => [
                $class => ClassDefinition::create($class)->constructor(['label' => 'configured']),
            ],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make($class, [ServerRequestInterface::class => $current]);

    expect($target->request)->toBe($selected)
        ->and($target->context)->toBe('current-context')
        ->and($target->label)->toBe('configured');
})->with([
    'Config attribute' => [true],
    'container service' => [false],
]);

test('ClassDefinition still accepts explicit request arguments by name or position', function (
    string|int $key,
): void {
    $selected = new ServerRequest('GET', '/selected');
    $current = new ServerRequest('GET', '/current', ['X-Context' => 'current-context']);
    $explicit = new ServerRequest('GET', '/explicit');
    $container = (new ContainerFactory())->create(
        new Config(['selected.request' => $selected], new Environment([])),
        new DependencyDefinitions([
            'factories' => [
                ConfiguredRequestSourceTarget::class => ClassDefinition::create(ConfiguredRequestSourceTarget::class)
                    ->constructor(['label' => 'configured']),
            ],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    $target = $container->make(ConfiguredRequestSourceTarget::class, [
        ServerRequestInterface::class => $current,
        $key => $explicit,
    ]);

    expect($target->request)->toBe($explicit)
        ->and($target->context)->toBe('current-context');
})->with([
    'name' => ['request'],
    'position' => [0],
]);
