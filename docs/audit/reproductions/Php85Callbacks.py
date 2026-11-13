"""Reproduce native attribute callback semantics outside PHP test discovery.

Example from the repository root:
    python3 docs/audit/reproductions/Php85Callbacks.py --php84 /path/php8.4 \
        --php85 /path/php8.5 --output /tmp/php85-callback-evidence.json

Some fixtures deliberately fail compilation or attribute materialization. Their
exit codes and diagnostics are evidence, not a successful DI regression suite.
Every exact generated PHP source is included once in the output JSON.
"""

from pathlib import Path
import argparse
import json
import os
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php84', required=True, help='Path to the PHP 8.4 CLI binary')
parser.add_argument('--php85', required=True, help='Path to the PHP 8.5 CLI binary')
parser.add_argument('--output', required=True, type=Path, help='Destination evidence JSON')
args = parser.parse_args()
repo = Path(__file__).resolve().parents[3]
binaries = {'php84': str(Path(args.php84).resolve()), 'php85': str(Path(args.php85).resolve())}
environment = os.environ.copy()
environment['COMPONENTA_DI_AUTOLOAD'] = str(repo / 'vendor/autoload.php')
environment['COMPONENTA_DI_SOURCE'] = str(repo / 'src')

cases = {
    'runtime_constant_on_php84': r'''
#[Attribute(Attribute::TARGET_CLASS)]
final class Callback { public function __construct(public Closure $value) {} }
define('RUNTIME_CALLBACK', static fn(): int => 42);
#[Callback(RUNTIME_CALLBACK)] final class Target {}
$attribute = (new ReflectionClass(Target::class))->getAttributes()[0]->newInstance();
echo json_encode(['value' => ($attribute->value)(), 'same_constant' => $attribute->value === RUNTIME_CALLBACK]), "\n";
''',
    'materialization_identity_and_state': r'''
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Callback { public function __construct(public Closure $value) {} }
function namedCounter(): int { static $value = 0; return ++$value; }
final class Counter {
    public static function next(): int { static $value = 0; return ++$value; }
    public const VALUE = static function (): int { static $value = 0; return ++$value; };
}
#[Callback(static function (): int { static $value = 0; return ++$value; })]
#[Callback(namedCounter(...))]
#[Callback(Counter::next(...))]
#[Callback(Counter::VALUE)]
final class Target {}
$observations = [];
foreach ((new ReflectionClass(Target::class))->getAttributes() as $index => $attribute) {
    $callbacks = [$attribute->getArguments()[0], $attribute->getArguments()[0], $attribute->newInstance()->value, $attribute->newInstance()->value];
    $references = array_map(WeakReference::create(...), $callbacks);
    $observations[['inline', 'named', 'static_method', 'constant'][$index]] = [
        'all_share_identity' => $callbacks[0] === $callbacks[1] && $callbacks[1] === $callbacks[2] && $callbacks[2] === $callbacks[3],
        'distinct_wrappers' => count(array_unique(array_map(spl_object_id(...), $callbacks))),
        'values' => [$callbacks[0](), $callbacks[0](), $callbacks[1](), $callbacks[2](), $callbacks[3]()],
    ];
    unset($callbacks);
    gc_collect_cycles();
    $observations[['inline', 'named', 'static_method', 'constant'][$index]]['alive_after_release'] = array_map(static fn(WeakReference $reference): bool => $reference->get() !== null, $references);
}
echo json_encode($observations), "\n";
''',
    'lexical_private_scope': r'''
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Callback { public function __construct(public Closure $value) {} }
#[Callback(self::secret(...))]
#[Callback(static function (): string { return self::secret(); })]
class DeclaringScope {
    #[Callback(self::secret(...))] public string $value;
    private static function secret(): string { return __CLASS__; }
}
final class InheritedScope extends DeclaringScope {}
$values = [];
foreach ((new ReflectionClass(DeclaringScope::class))->getAttributes() as $attribute) {
    $callback = $attribute->newInstance()->value;
    $reflection = new ReflectionFunction($callback);
    $values[] = [$callback(), $reflection->getClosureScopeClass()?->getName(), $reflection->getClosureThis()];
}
$inherited = (new ReflectionProperty(InheritedScope::class, 'value'))->getAttributes()[0]->newInstance()->value;
echo json_encode(['class_attributes' => $values, 'inherited_property' => $inherited()]), "\n";
''',
    'trait_relative_attributes': r'''
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Callback { public function __construct(public Closure $value) {} }
trait RelativeCallbacks {
    #[Callback(self::who(...))] public string $firstClass;
    #[Callback(static function (): string { return self::who(); })] public string $inline;
}
final class First { use RelativeCallbacks; public static function who(): string { return 'first'; } }
final class Second { use RelativeCallbacks; public static function who(): string { return 'second'; } }
$actual = [];
foreach (['firstClass', 'inline'] as $property) {
    foreach ([First::class, Second::class] as $class) {
        $callback = (new ReflectionProperty($class, $property))->getAttributes()[0]->newInstance()->value;
        $actual[$property][] = $callback();
    }
}
echo json_encode(['actual' => $actual, 'required_per_consumer' => ['first', 'second'], 'fcc_engine_defect_reproduced' => $actual['firstClass'] !== ['first', 'second']]), "\n";
''',
    'trait_parent_attributes': r'''
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Callback { public function __construct(public Closure $value) {} }
class FirstParent { public static function who(): string { return 'first-parent'; } }
class SecondParent { public static function who(): string { return 'second-parent'; } }
trait ParentCallback { #[Callback(parent::who(...))] public string $value; }
final class First extends FirstParent { use ParentCallback; }
final class Second extends SecondParent { use ParentCallback; }
$actual = [];
foreach ([First::class, Second::class] as $class) {
    $callback = (new ReflectionProperty($class, 'value'))->getAttributes()[0]->newInstance()->value;
    $actual[] = $callback();
}
echo json_encode(['actual' => $actual, 'required_per_consumer' => ['first-parent', 'second-parent']]), "\n";
''',
    'trait_private_scope_leak': r'''
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Callback { public function __construct(public Closure $value) {} }
trait PrivateCallback { #[Callback(First::secret(...))] public string $value; }
final class First { use PrivateCallback; private static function secret(): string { return 'private-first'; } }
final class Second { use PrivateCallback; }
$actual = [];
foreach ([First::class, Second::class] as $class) {
    try {
        $callback = (new ReflectionProperty($class, 'value'))->getAttributes()[0]->newInstance()->value;
        $actual[] = ['value' => $callback()];
    } catch (Error $failure) {
        $actual[] = ['error' => $failure::class, 'message' => $failure->getMessage()];
    }
}
echo json_encode(['actual' => $actual, 'required_second' => 'visibility Error']), "\n";
''',
    'invalid_arrow': r'''
#[Attribute] final class Callback { public function __construct(public Closure $value) {} }
#[Callback(static fn(): int => 1)] final class Target {}
echo "compiled\n";
(new ReflectionClass(Target::class))->getAttributes()[0]->newInstance();
''',
    'invalid_capture': r'''
#[Attribute] final class Callback { public function __construct(public Closure $value) {} }
$value = 1;
#[Callback(static function () use ($value): int { return $value; })] final class Target {}
echo "compiled\n";
(new ReflectionClass(Target::class))->getAttributes()[0]->newInstance();
''',
    'invalid_nonstatic_literal': r'''
#[Attribute] final class Callback { public function __construct(public Closure $value) {} }
#[Callback(function (): int { return 1; })] final class Target {}
echo "compiled\n";
(new ReflectionClass(Target::class))->getAttributes()[0]->newInstance();
''',
    'invalid_dynamic_reference': r'''
#[Attribute] final class Callback { public function __construct(public Closure $value) {} }
final class Helper { public static function value(): int { return 1; } }
#[Callback([Helper::class, 'value'](...))] final class Target {}
echo "compiled\n";
(new ReflectionClass(Target::class))->getAttributes()[0]->newInstance();
''',
    'invalid_magic_reference': r'''
#[Attribute] final class Callback { public function __construct(public Closure $value) {} }
final class Helper { public static function __callStatic(string $method, array $arguments): int { return 1; } }
#[Callback(Helper::missing(...))] final class Target {}
echo "compiled\n";
(new ReflectionClass(Target::class))->getAttributes()[0]->newInstance();
''',
    'property_widening_subclass_break': r'''
readonly class ParentAttribute { public function __construct(public string|Closure $method) {} }
readonly class ExistingChild extends ParentAttribute { public string $method; }
echo "compiled\n";
''',
}

init = r'''
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Tests\Support\ContainerBuilder;
#[Attribute] final class UnusedMarker {}
final class NoOpRule implements AttributeCompositionRuleInterface { public function validate(AttributeUsage $attribute, AttributeSet $set): void {} }
final class Dependency { public string $label = 'injected'; }
function namedCounter(): int { static $count = 0; return ++$count; }
final class SharedCounter { public const VALUE = static function (): int { static $count = 0; return ++$count; }; }
final class Target {
    #[Init(static function (): int { static $count = 0; return ++$count; })] public int $inline;
    #[Init(namedCounter(...))] public int $named;
    #[Init(SharedCounter::VALUE)] public int $constant;
    #[Init(self::makeDependency(...))] public readonly string $dependency;
    private static function makeDependency(Dependency $dependency): string { return $dependency->label; }
}
final class LifetimeTarget {
    #[Init(static function (): WeakReference { static $held; $held ??= new stdClass(); return WeakReference::create($held); })]
    public WeakReference $reference;
}
final class Calls { public static int $count = 0; }
#[Lazy] final class Delayed {
    #[Init(static function (): string { ++Calls::$count; return 'initialized'; })] public string $value;
}
$builder = new ContainerBuilder();
if (UNCACHED_MODE) { $builder->addAttributeDefinition(new AttributeDefinition(UnusedMarker::class, rules: [new NoOpRule()])); }
$container = $builder->build();
$values = [];
for ($i = 0; $i < 3; ++$i) {
    $value = $container->make(Target::class);
    $values[] = [$value->inline, $value->named, $value->constant, $value->dependency];
}
$first = $container->get(Target::class);
$second = $container->get(Target::class);
$lifetime = $container->make(LifetimeTarget::class);
$delayed = $container->make(Delayed::class);
$before = Calls::$count;
$value = $delayed->value;
echo json_encode(['source_map' => $sourceMap, 'make_values' => $values, 'get_shared_identity' => $first === $second, 'inline_static_object_retained' => $lifetime->reference->get() !== null, 'lazy' => [$before, $value, Calls::$count]]), "\n";
'''
init_prefix = r'''
require getenv('COMPONENTA_DI_AUTOLOAD');
$sourceDirectory = realpath(getenv('COMPONENTA_DI_SOURCE'));
$sourceMap = [
    'Componenta\\DI\\Container' => [
        'actual' => realpath((new ReflectionClass(Componenta\DI\Container::class))->getFileName()),
        'expected' => $sourceDirectory . '/Container.php',
    ],
    'Componenta\\DI\\Internal\\is_entry_class_eligible' => [
        'actual' => realpath((new ReflectionFunction('Componenta\\DI\\Internal\\is_entry_class_eligible'))->getFileName()),
        'expected' => $sourceDirectory . '/Internal/functions.php',
    ],
];
foreach ($sourceMap as $symbol => &$mapping) {
    $mapping['verified'] = $sourceDirectory !== false && $mapping['actual'] === $mapping['expected'];
    if (!$mapping['verified']) {
        throw new RuntimeException('Unexpected DI source mapping for ' . $symbol . ': ' . json_encode($mapping));
    }
}
unset($mapping);
'''
cases['init_current_cached'] = init_prefix + init.replace('UNCACHED_MODE', 'false')
cases['init_current_uncached'] = init_prefix + init.replace('UNCACHED_MODE', 'true')

sources = {name + '.php': '<?php\ndeclare(strict_types=1);\n' + source for name, source in cases.items()}
results = []
with tempfile.TemporaryDirectory(prefix='componenta-di-native-callbacks-') as temporary:
    directory = Path(temporary)
    for name, source in sources.items():
        file = directory / name
        file.write_text(source)
        for runtime, binary in binaries.items():
            command = [binary, '-n', str(file)]
            process = subprocess.run(command, env=environment, capture_output=True, text=True)
            row = {'case': file.stem, 'fixture': name, 'runtime': runtime, 'command': command, 'exit_code': process.returncode, 'stdout': process.stdout, 'stderr': process.stderr}
            if process.returncode == 0:
                try:
                    row['observed'] = json.loads(process.stdout)
                except json.JSONDecodeError:
                    pass
            results.append(row)

record = {
    'schema_version': 1,
    'source_commit': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=repo, text=True).strip(),
    'src_tree': subprocess.check_output(['git', 'rev-parse', 'HEAD:src'], cwd=repo, text=True).strip(),
    'source_matches_head': subprocess.run(['git', 'diff', '--quiet', 'HEAD', '--', 'src'], cwd=repo).returncode == 0,
    'binaries': {name: {'path': binary, 'version': subprocess.check_output([binary, '-n', '-r', 'echo PHP_VERSION;'], text=True)} for name, binary in binaries.items()},
    'fixture_environment': {key: environment[key] for key in ('COMPONENTA_DI_AUTOLOAD', 'COMPONENTA_DI_SOURCE')},
    'fixtures': sources,
    'results': results,
    'limits': [
        'Native syntax failures are isolated in subprocesses; exit 255 alone is not a DI defect.',
        'Trait-relative first-class callable behavior is recorded as an upstream engine defect, with required and observed outcomes separated.',
        'The PHP 8.5.8 run cannot establish the minimum fixed stable PHP 8.5 patch.',
        'No OPcache/JIT matrix or throughput benchmark is performed by this script.',
    ],
}
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(record, ensure_ascii=False, indent=2) + '\n')
print(json.dumps({'output': str(args.output), 'cases': len(cases), 'runs': len(results), 'source_commit': record['source_commit'], 'source_matches_head': record['source_matches_head']}))
