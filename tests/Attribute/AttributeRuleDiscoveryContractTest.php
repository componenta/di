<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributeRuleDiscoveryContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use ReflectionClass;
use Reflector;
use RuntimeException;
use Throwable;

#[Attribute(Attribute::TARGET_CLASS)]
final class DiscoveredMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final class RequiredMarker {}

final class NativeArgument
{
    /** @param class-string|null $source */
    public function __construct(string $alias, ?string $source = DiscoveredMarker::class)
    {
        if ($source !== null && !class_exists($alias, false)) {
            class_alias($source, $alias);
        }
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class LoadingMarker
{
    public function __construct(public NativeArgument $argument) {}
}

final class ArgumentReadingRule implements AttributeCompositionRuleInterface
{
    public bool $enabled = true;

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if ($this->enabled && !($attribute->arguments[0] ?? null) instanceof NativeArgument) {
            throw new LogicException('Expected the native argument fixture.');
        }
    }
}

final class RequireDiscoveredMarker implements AttributeCompositionRuleInterface
{
    public bool $enabled = true;

    /** @var list<bool> */
    public array $observed = [];

    public function __construct(private readonly Throwable $failure) {}

    public function validate(AttributeUsage $attribute, AttributeSet $set): void
    {
        if (!$this->enabled) {
            return;
        }
        if (!($attribute->arguments[0] ?? null) instanceof NativeArgument) {
            throw new LogicException('Expected the native argument fixture.');
        }

        $companion = $set->has(DiscoveredMarker::class);
        $this->observed[] = $companion;
        if (!$companion) {
            throw $this->failure;
        }
    }
}

final class CountingHandler implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
    }
}

/**
 * @param class-string|null $source
 * @return ReflectionClass<object>
 */
function target(?string $source = DiscoveredMarker::class): ReflectionClass
{
    $suffix = bin2hex(random_bytes(6));
    $alias = 'LateMarker_' . $suffix;
    $class = 'Target_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s, LoadingMarker(new NativeArgument(%s, %s))] final class %s {}',
        __NAMESPACE__,
        $alias,
        var_export(__NAMESPACE__ . '\\' . $alias, true),
        var_export($source, true),
        $class,
    ));
    $name = __NAMESPACE__ . '\\' . $class;
    if (!class_exists($name)) {
        throw new LogicException('Expected the rule discovery target.');
    }

    return new ReflectionClass($name);
}

test('direct plans include metadata discovered while a rule reads native arguments', function (bool $warm): void {
    $handler = new CountingHandler();
    $rule = new ArgumentReadingRule();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(DiscoveredMarker::class, $handler));
    $registry->register(new AttributeDefinition(LoadingMarker::class, $handler, rules: [$rule]));
    $registry->seal();
    $builder = new AttributePlanBuilder($registry);
    $target = target();
    if ($warm) {
        $rule->enabled = false;
        expect($builder->build($target)->attributes(DiscoveredMarker::class))->toBe([]);
        $rule->enabled = true;
    }

    $plan = $builder->build($target);

    expect($plan->attributes(DiscoveredMarker::class))->toHaveCount(1)
        ->and($plan->attributes(LoadingMarker::class))->toHaveCount(1)
        ->and($handler->calls)->toBe(0);

    expect($builder->build($target)->attributes(DiscoveredMarker::class))->toHaveCount(1)
        ->and($handler->calls)->toBe(0);
})->with(['cold metadata' => false, 'previously inspected metadata' => true]);

test('the first usable plan rechecks a rule that discloses its required companion', function (bool $targeted, string $state): void {
    $handler = new CountingHandler();
    $rule = new RequireDiscoveredMarker(new AttributeCompositionException('Expected a discovered companion.'));
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: $targeted);
    $registry->register(new AttributeDefinition(DiscoveredMarker::class, $handler));
    $registry->register(new AttributeDefinition(LoadingMarker::class, $handler, rules: [$rule]));
    $registry->seal();
    $builder = new AttributePlanBuilder($registry);
    $target = target();
    if ($state === 'warm') {
        $rule->enabled = false;
        expect($builder->build($target)->attributes(DiscoveredMarker::class))->toBe([]);
        $rule->enabled = true;
    } elseif ($state === 'preloaded') {
        class_alias(DiscoveredMarker::class, $target->getAttributes()[0]->getName(), false);
    }

    $plan = $builder->build($target);

    expect($plan->attributes(DiscoveredMarker::class))->toHaveCount(1)
        ->and($plan->attributes(LoadingMarker::class)[0]->definition->rules)->toBe([$rule])
        ->and($rule->observed)->toBe($state === 'preloaded' ? [true] : [false, true])
        ->and($handler->calls)->toBe(0);
})->with(['default discovery' => false, 'targeted discovery' => true])
    ->with(['cold', 'warm', 'preloaded']);

test('rule failures survive absent and unrelated declarations and preserve a foreign cause', function (bool $targeted, string $scenario): void {
    $source = match ($scenario) {
        'absent' => null,
        'unregistered' => RequiredMarker::class,
        'foreign failure' => DiscoveredMarker::class,
        default => throw new LogicException('Expected a rule failure fixture.'),
    };
    $failure = $scenario === 'foreign failure'
        ? new RuntimeException('Foreign rule failure.')
        : new AttributeCompositionException('Expected a discovered companion.');
    $rule = new RequireDiscoveredMarker($failure);
    $registry = new AttributeDefinitionRegistry(targetedDiscovery: $targeted);
    $registry->register(new AttributeDefinition(DiscoveredMarker::class));
    $registry->register(new AttributeDefinition(LoadingMarker::class, rules: [$rule]));
    $registry->seal();
    $builder = new AttributePlanBuilder($registry);
    $target = target($source);
    $alias = $target->getAttributes()[0]->getName();

    try {
        $builder->build($target);
        \PHPUnit\Framework\Assert::fail('Expected the original rule failure.');
    } catch (AttributeCompositionException $error) {
        if ($scenario === 'foreign failure') {
            expect($error->getPrevious())->toBe($failure);
        } else {
            expect($error)->toBe($failure);
        }
    }

    expect($rule->observed)->toBe([false])
        ->and(class_exists($alias, false))->toBe($scenario !== 'absent');
})->with(['default discovery' => false, 'targeted discovery' => true])
    ->with(['absent', 'unregistered', 'foreign failure']);

test('direct plans include attributes discovered during cold plan materialization', function (
    string $mode,
    string $timing,
    string $component,
    string $discovery,
): void {
    $root = dirname(__DIR__, 2);
    $script = <<<'PHP'
        use Componenta\DI\Attribute\Composition\AttributeDefinition;
        use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
        use Componenta\DI\Attribute\Composition\AttributePlan;
        use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
        use Componenta\DI\Internal\ResolutionMetadata;
        use Componenta\DI\Tests\Support\SubprocessAutoload;

        #[Attribute(Attribute::TARGET_CLASS)] final class MaterializedAttribute {}
        #[LateMaterializedAttribute] final class MaterializedTarget {}
        #[MaterializedAttribute] final class RegisteredTarget {}

        require $argv[1];
        SubprocessAutoload::load($argv[2]);
        $registry = new AttributeDefinitionRegistry(targetedDiscovery: $argv[3] === 'targeted');
        $registerDefinition = $argv[6] === 'definition';
        if (!$registerDefinition) {
            $registry->register(new AttributeDefinition(MaterializedAttribute::class));
            $registry->seal();
        }
        $builder = new AttributePlanBuilder($registry);
        $autoloadedClass = AttributePlan::class;
        if ($argv[5] === 'snapshot') {
            class_exists(AttributePlan::class);
            $autoloadedClass = ResolutionMetadata::class;
        }
        if (class_exists($autoloadedClass, false)) {
            throw new LogicException('The plan component must start cold.');
        }

        $loads = 0;
        $loader = static function (string $class) use ($autoloadedClass, $registerDefinition, $registry, &$loads): void {
            if ($class === $autoloadedClass) {
                ++$loads;
                if ($registerDefinition) {
                    $registry->register(new AttributeDefinition(MaterializedAttribute::class));
                } else {
                    class_alias(MaterializedAttribute::class, LateMaterializedAttribute::class, false);
                }
            }
        };
        spl_autoload_register($loader, prepend: true);
        try {
            if ($argv[4] === 'before') {
                class_exists($autoloadedClass);
            }
            $target = new ReflectionClass($registerDefinition ? RegisteredTarget::class : MaterializedTarget::class);
            $first = $builder->build($target);
            $second = $builder->build($target);
        } finally {
            spl_autoload_unregister($loader);
        }

        echo json_encode([
            'loads' => $loads,
            'first' => count($first->attributes(MaterializedAttribute::class)),
            'second' => count($second->attributes(MaterializedAttribute::class)),
            'warm' => $first === $second,
        ], JSON_THROW_ON_ERROR);
        PHP;
    $process = proc_open([
        PHP_BINARY,
        '-n',
        '-r',
        $script,
        $root . '/tests/Support/SubprocessAutoload.php',
        $root . '/vendor/autoload.php',
        $mode,
        $timing,
        $component,
        $discovery,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new LogicException('Could not start the cold plan fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stdout . $stderr)
        ->and($stderr)->toBe('')
        ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'loads' => 1,
            'first' => 1,
            'second' => 1,
            'warm' => true,
        ]);
})->with(['compatible', 'targeted'])
    ->with(['during materialization' => 'inside', 'preloaded component' => 'before'])
    ->with(['plan class' => 'plan', 'snapshot dependency' => 'snapshot'])
    ->with(['late alias' => 'alias', 'late semantic definition' => 'definition']);

test('the first direct plan rejects constraints introduced by rule-time discovery', function (bool $requires): void {
    $handler = new CountingHandler();
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(
        DiscoveredMarker::class,
        $handler,
        requires: $requires ? [RequiredMarker::class] : [],
        forbids: $requires ? [] : [LoadingMarker::class],
    ));
    $registry->register(new AttributeDefinition(LoadingMarker::class, $handler, rules: [new ArgumentReadingRule()]));
    $registry->seal();
    $builder = new AttributePlanBuilder($registry);
    $target = target();

    expect(fn() => $builder->build($target))
        ->toThrow(AttributeCompositionException::class, $requires ? 'requires' : 'forbids')
        ->and($handler->calls)->toBe(0);
})->with(['missing required attribute' => true, 'forbidden combination' => false]);
