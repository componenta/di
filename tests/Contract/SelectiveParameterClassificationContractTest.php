<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SelectiveParameterClassification;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Classified {}

final readonly class WarmConstructor
{
    public function __construct(#[Classified] public string $constructorLabel = 'default') {}
}

function warmCallable(#[Classified] string $callableLabel = 'default'): string
{
    return $callableLabel;
}

final class ClassificationProbe implements ParameterResolverInterface
{
    /** @var array<string,int> */
    public array $checks = [];

    public function supports(ParameterTarget $target): bool
    {
        if (!in_array($target->name, ['constructorLabel', 'callableLabel', 'lateLabel'], true)) {
            return false;
        }

        $this->checks[$target->name] = ($this->checks[$target->name] ?? 0) + 1;

        return $target->hasAttribute(Classified::class);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, 'selected'];
    }
}

test('targeted discovery preserves warm classifications for an unrelated late attribute', function (): void {
    eval(sprintf('namespace %s; function discoverPending(#[PendingClassification] string $lateLabel = "default"): string { return $lateLabel; }', __NAMESPACE__));
    $pendingFunction = __NAMESPACE__ . '\\discoverPending';
    $probe = new ClassificationProbe();
    $container = (new ContainerFactory())->createWithTargetedAttributeDiscovery(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            ConfigKey::ATTRIBUTE_DEFINITIONS => [new AttributeDefinition(Classified::class)],
            ConfigKey::PARAMETER_RESOLVERS => [1000 => $probe],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new LogicException('Expected the DI test container.');
    }

    $warm = static function () use ($container): void {
        expect(fixtureInstance($container->make(WarmConstructor::class), WarmConstructor::class)->constructorLabel)->toBe('selected')
            ->and($container->call(__NAMESPACE__ . '\\warmCallable'))->toBe('selected');
    };

    expect($container->call($pendingFunction))->toBe('default');
    $warm();
    $before = $probe->checks;
    $warm();
    expect($probe->checks)->toBe($before);

    class_alias(Classified::class, __NAMESPACE__ . '\\PendingClassification', autoload: false);

    $warm();
    expect($probe->checks)->toBe($before);
    expect($container->call($pendingFunction))->toBe('selected')
        ->and($probe->checks['lateLabel'])->toBe(2);
});
