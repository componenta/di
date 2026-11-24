<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ParameterResolverRegistrationCold;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterAttributeAccess;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\AttributeParameterResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use ReflectionFunction;

if (!isset($argv[1], $argv[2])) {
    throw new LogicException('Expected the autoload path and the registration scenario.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
$loader = SubprocessAutoload::load($argv[1]);
$scenario = $argv[2];

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Label {}

$registry = new AttributeDefinitionRegistry();
$registry->register(new AttributeDefinition(Label::class, new class () implements ParameterAttributeHandlerInterface {
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return ParameterAttributeValue::resolved('attribute');
    }
}));
$plans = new AttributePlanBuilder($registry);
$pipeline = new ParametersResolver($plans);
$pipeline->add(new DefaultValueResolver());
$resolver = new AttributeParameterResolver($plans);

if (class_exists(ParameterAttributeAccess::class, false)) {
    throw new LogicException('The attribute resolver bridge must be loaded by registration.');
}

$loads = 0;
$load = static function (string $class) use ($loader, $pipeline, $resolver, $scenario, &$loads): void {
    if ($class !== ParameterAttributeAccess::class) {
        return;
    }
    $loader->loadClass($class);
    ++$loads;
    match ($scenario) {
        'seal' => $pipeline->seal(),
        'register' => $pipeline->add($resolver, 20),
        'control' => null,
        default => throw new LogicException('Unknown registration scenario.'),
    };
};

$failure = null;
spl_autoload_register($load, prepend: true);
try {
    $pipeline->add($resolver, $scenario === 'register' ? -20 : 20);
} catch (InvalidConfigurationException $error) {
    $failure = $error->getMessage();
} finally {
    spl_autoload_unregister($load);
}

$pipeline->seal();
$target = new ReflectionFunction(static fn(#[Label] string $value = 'fallback'): string => $value);
echo json_encode([
    'loads' => $loads,
    'failure' => $failure,
    'result' => $pipeline->resolve($target->getParameters()),
], JSON_THROW_ON_ERROR);
