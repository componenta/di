# Independent dev recheck

Commit checked: 01c5a53d7ed708ecf07e9768d873bb5163e673a4

| Check | Exit code |
|---|---:|
| composer install | 0 |
| composer validate --strict | 0 |
| composer audit | 0 |
| PHP lint | 0 |
| PHPStan level max, full src | 1 |
| composer cs-check | 8 |
| Pest, four deterministic random seeds | 0 |

## composer-validate

```text
./composer.json is valid
```

## composer-audit

```text
No security vulnerability advisories found.
```

## phpstan-stderr

```text
```

## cs-check

```text
         ->build();
 
@@ -77,7 +82,7 @@
     $container = (new ContainerBuilder())
         ->addFactory(
             ProxyInjectionContract::class,
-            static fn(): ProxyInjectionContract => new ProxyInjectionService(),
+            static fn (): ProxyInjectionContract => new ProxyInjectionService(),
         )
         ->build();
 

      ----------- end diff -----------

  99) tests/DefinitionReplacementTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/DefinitionReplacementTest.php
+++ /home/runner/work/di/di/tests/DefinitionReplacementTest.php
@@ -6,7 +6,9 @@
 
 use Componenta\DI\Definition\Definition;
 
-final readonly class ReplacementInvokableService {}
+final readonly class ReplacementInvokableService
+{
+}
 
 it('uses the latest runtime definition when its resolver kind changes', function (): void {
     $container = minimalContainer();

      ----------- end diff -----------

 100) tests/PublicApiSignatureTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/PublicApiSignatureTest.php
+++ /home/runner/work/di/di/tests/PublicApiSignatureTest.php
@@ -25,8 +25,8 @@
     string $method,
     array $expected,
 ) {
-    $names = static fn(string $class): array => array_map(
-        static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
+    $names = static fn (string $class): array => array_map(
+        static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
         (new \ReflectionMethod($class, $method))->getParameters(),
     );
 

      ----------- end diff -----------

 101) tests/InvokableAliasConflictTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/InvokableAliasConflictTest.php
+++ /home/runner/work/di/di/tests/InvokableAliasConflictTest.php
@@ -7,8 +7,12 @@
 use Componenta\DI\ContainerBuilder;
 use Componenta\DI\Exception\InvalidConfigurationException;
 
-final readonly class ExistingInvokableAliasTarget {}
-final readonly class RequestedInvokableAliasTarget {}
+final readonly class ExistingInvokableAliasTarget
+{
+}
+final readonly class RequestedInvokableAliasTarget
+{
+}
 
 it('rejects a keyed invokable that conflicts with an existing alias', function (): void {
     $config = new Config([

      ----------- end diff -----------

 102) tests/Architecture/CompiledFactoryParityTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Architecture/CompiledFactoryParityTest.php
+++ /home/runner/work/di/di/tests/Architecture/CompiledFactoryParityTest.php
@@ -9,7 +9,9 @@
 use Componenta\DI\ConfigKey;
 use Componenta\DI\ContainerBuilder;
 
-final readonly class CompiledParityDependencyForTest {}
+final readonly class CompiledParityDependencyForTest
+{
+}
 
 #[SetUp('initialize')]
 final class CompiledParityEntryForTest
@@ -22,7 +24,8 @@
     public function __construct(
         public CompiledParityDependencyForTest $constructor,
         public int $value = 1,
-    ) {}
+    ) {
+    }
 
     public function initialize(CompiledParityDependencyForTest $dependency): void
     {

      ----------- end diff -----------

 103) tests/CallableResolverTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/CallableResolverTest.php
+++ /home/runner/work/di/di/tests/CallableResolverTest.php
@@ -5,6 +5,7 @@
 use Componenta\DI\CallableResolver;
 use Componenta\DI\Exception\InvalidCallableException;
 use Componenta\DI\NullContainer;
+
 require_once __DIR__ . '/Fixture/functions.php';
 
 use Componenta\DI\Tests\Fixture\InvokableService;
@@ -15,7 +16,9 @@
 function mapContainer(array $entries): ContainerInterface
 {
     return new class ($entries) implements ContainerInterface {
-        public function __construct(private array $entries) {}
+        public function __construct(private array $entries)
+        {
+        }
 
         public function get(string $id): mixed
         {

      ----------- end diff -----------

 104) tests/CompositeResolverConstructionTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/CompositeResolverConstructionTest.php
+++ /home/runner/work/di/di/tests/CompositeResolverConstructionTest.php
@@ -13,7 +13,8 @@
     public function __construct(
         private string $id,
         private string $value,
-    ) {}
+    ) {
+    }
 
     public function can(string $id): bool
     {

      ----------- end diff -----------

 105) tests/NestedReferenceDefinitionTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/NestedReferenceDefinitionTest.php
+++ /home/runner/work/di/di/tests/NestedReferenceDefinitionTest.php
@@ -6,12 +6,16 @@
 
 use Componenta\DI\Definition\Definition;
 
-final readonly class NestedReferenceDependency {}
+final readonly class NestedReferenceDependency
+{
+}
 
 final readonly class NestedReferenceConsumer
 {
     /** @param array<string, array<string, object>> $dependencies */
-    public function __construct(public array $dependencies) {}
+    public function __construct(public array $dependencies)
+    {
+    }
 }
 
 it('resolves reference definitions recursively inside constructor arrays', function (): void {

      ----------- end diff -----------

 106) tests/ExternalContainerRegistryTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/ExternalContainerRegistryTest.php
+++ /home/runner/work/di/di/tests/ExternalContainerRegistryTest.php
@@ -12,7 +12,9 @@
     public int $hasCalls = 0;
 
     /** @param array<string, mixed> $entries */
-    public function __construct(private array $entries) {}
+    public function __construct(private array $entries)
+    {
+    }
 
     public function get(string $id): mixed
     {

      ----------- end diff -----------

 107) verification/run.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/verification/run.php
+++ /home/runner/work/di/di/verification/run.php
@@ -11,7 +11,9 @@
 
 require dirname(__DIR__, 4) . '/vendor/autoload.php';
 
-final readonly class Dependency {}
+final readonly class Dependency
+{
+}
 
 final readonly class Entry
 {
@@ -18,7 +20,8 @@
     public function __construct(
         public Dependency $dependency,
         public int $value = 1,
-    ) {}
+    ) {
+    }
 }
 
 $directory = sys_get_temp_dir() . '/componenta-di-verification-' . bin2hex(random_bytes(5));

      ----------- end diff -----------

 108) benchmarks/GeneratedVsReflectionBench.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/benchmarks/GeneratedVsReflectionBench.php
+++ /home/runner/work/di/di/benchmarks/GeneratedVsReflectionBench.php
@@ -10,7 +10,9 @@
 
 require dirname(__DIR__, 4) . '/vendor/autoload.php';
 
-final readonly class BenchmarkDependency {}
+final readonly class BenchmarkDependency
+{
+}
 
 final readonly class BenchmarkEntry
 {
@@ -18,7 +20,8 @@
         public BenchmarkDependency $dependency,
         public int $number = 1,
         public string $name = 'default',
-    ) {}
+    ) {
+    }
 }
 
 /** @return array{nanoseconds: float, operations: float} */

      ----------- end diff -----------

 109) benchmarks/RuntimeBench.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/benchmarks/RuntimeBench.php
+++ /home/runner/work/di/di/benchmarks/RuntimeBench.php
@@ -27,13 +27,19 @@
 namespace Componenta\DI\Benchmarks\Runtime {
     use Componenta\DI\ContainerBuilder;
 
-    final class Dependency {}
+    final class Dependency
+    {
+    }
 
-    final class NoArguments {}
+    final class NoArguments
+    {
+    }
 
     final readonly class ConstructorTarget
     {
-        public function __construct(public Dependency $dependency) {}
+        public function __construct(public Dependency $dependency)
+        {
+        }
     }
 
     final class MethodTarget
@@ -76,34 +82,34 @@
     $iterations = max(10_000, (int) ($_SERVER['DI_BENCH_ITERATIONS'] ?? 100_000));
     $buildIterations = max(100, (int) ($_SERVER['DI_BUILD_ITERATIONS'] ?? 2_000));
     $container = (new ContainerBuilder())->build();
-    $closure = static fn(Dependency $dependency): Dependency => $dependency;
+    $closure = static fn (Dependency $dependency): Dependency => $dependency;
     $method = [new MethodTarget(), 'execute'];
 
     $cases = [
         'build/default' => [
-            static fn(): object => (new ContainerBuilder())->build(),
+            static fn (): object => (new ContainerBuilder())->build(),
             $buildIterations,
         ],
         'make/no-arguments' => [
-            static fn(): object => $container->make(NoArguments::class),
+            static fn (): object => $container->make(NoArguments::class),
             $iterations,
         ],
         'make/autowire' => [
-            static fn(): object => $container->make(ConstructorTarget::class),
+            static fn (): object => $container->make(ConstructorTarget::class),
             $iterations,
         ],
         'call/reused-closure' => [
-            static fn(): mixed => $container->call($closure),
+            static fn (): mixed => $container->call($closure),
             $iterations,
         ],
         'call/fresh-closure' => [
-            static fn(): mixed => $container->call(
-                static fn(Dependency $dependency): Dependency => $dependency,
+            static fn (): mixed => $container->call(
+                static fn (Dependency $dependency): Dependency => $dependency,
             ),
             $iterations,
         ],
         'call/method-array' => [
-            static fn(): mixed => $container->call($method),
+            static fn (): mixed => $container->call($method),
             $iterations,
         ],
     ];

      ----------- end diff -----------

 110) tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
+++ /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
@@ -10,7 +10,9 @@
 function entryIdUnwrapperContainer(array $entries): ContainerInterface
 {
     return new class ($entries) implements ContainerInterface {
-        public function __construct(private array $entries) {}
+        public function __construct(private array $entries)
+        {
+        }
 
         public function get(string $id): mixed
         {

      ----------- end diff -----------

 111) tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
+++ /home/runner/work/di/di/tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
@@ -11,7 +11,9 @@
 function configUnwrapperContainer(mixed $value): ContainerInterface
 {
     return new class ($value) implements ContainerInterface {
-        public function __construct(private mixed $value) {}
+        public function __construct(private mixed $value)
+        {
+        }
 
         public function get(string $id): mixed
         {

      ----------- end diff -----------

 112) tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
+++ /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
@@ -14,7 +14,9 @@
     $config = $env === null ? null : new Config([], $env);
 
     return new class ($config) implements ContainerInterface {
-        public function __construct(private ?Config $config) {}
+        public function __construct(private ?Config $config)
+        {
+        }
 
         public function get(string $id): mixed
         {

      ----------- end diff -----------

 113) tests/Resolver/CompositeResolverTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/CompositeResolverTest.php
+++ /home/runner/work/di/di/tests/Resolver/CompositeResolverTest.php
@@ -16,7 +16,9 @@
         public int $canCalls = 0;
         public int $resolveCalls = 0;
 
-        public function __construct(private array $ids, private array $values) {}
+        public function __construct(private array $ids, private array $values)
+        {
+        }
 
         public function can(string $id): bool
         {
@@ -37,7 +39,9 @@
     return new class ($supported) implements EntryResolverInterface, DefinitionAwareResolverInterface {
         public array $definitions = [];
 
-        public function __construct(private array $supported) {}
+        public function __construct(private array $supported)
+        {
+        }
 
         public function can(string $id): bool
         {
@@ -90,7 +94,10 @@
         $capturing = new class () implements EntryResolverInterface {
             public array $context = [];
 
-            public function can(string $id): bool { return $id === 'svc'; }
+            public function can(string $id): bool
+            {
+                return $id === 'svc';
+            }
             public function resolve(string $id, array $context = []): mixed
             {
                 $this->context = $context;

      ----------- end diff -----------

 114) tests/Resolver/TypeHintsMatchesTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/TypeHintsMatchesTest.php
+++ /home/runner/work/di/di/tests/Resolver/TypeHintsMatchesTest.php
@@ -6,7 +6,7 @@
 
 it('accepts an integer for a float declaration like PHP does', function (): void {
     $parameter = (new ReflectionFunction(
-        static fn(float $value): float => $value,
+        static fn (float $value): float => $value,
     ))->getParameters()[0];
 
     expect(TypeHints::matches($parameter->getType(), 1))->toBeTrue()

      ----------- end diff -----------

 115) tests/Resolver/FactoryResolverTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/FactoryResolverTest.php
+++ /home/runner/work/di/di/tests/Resolver/FactoryResolverTest.php
@@ -2,9 +2,9 @@
 
 declare(strict_types=1);
 
-use Componenta\DI\Definition\ClassDefinition;
 use Componenta\Config\Config;
 use Componenta\Config\ContainerValue;
+use Componenta\DI\Definition\ClassDefinition;
 use Componenta\DI\Definition\Definition;
 use Componenta\DI\Definition\FactoryDefinition;
 use Componenta\DI\Definition\InvokableDefinition;
@@ -24,7 +24,9 @@
 function smallContainer(array $entries = []): ContainerInterface
 {
     return new class ($entries) implements ContainerInterface {
-        public function __construct(private array $entries) {}
+        public function __construct(private array $entries)
+        {
+        }
 
         public function get(string $id): mixed
         {
@@ -216,7 +218,8 @@
                          */
                         public function __construct(
                             public array $context,
-                        ) {}
+                        ) {
+                        }
                     };
                 }
 
@@ -236,7 +239,9 @@
         it('wraps foreign Throwables from the factory into ResolutionException', function () {
             $boom = new RuntimeException('factory boom');
             $resolver = makeFactoryResolver([
-                'svc' => function () use ($boom) { throw $boom; },
+                'svc' => function () use ($boom) {
+                    throw $boom;
+                },
             ]);
 
             try {
@@ -252,7 +257,9 @@
         it('lets ContainerExceptionInterface exceptions propagate unchanged', function () {
             $original = NotFoundException::forService('inner');
             $resolver = makeFactoryResolver([
-                'svc' => function () use ($original) { throw $original; },
+                'svc' => function () use ($original) {
+                    throw $original;
+                },
             ]);
 
             try {

      ----------- end diff -----------

 116) tests/Architecture/DevelopmentProductionParityTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Architecture/DevelopmentProductionParityTest.php
+++ /home/runner/work/di/di/tests/Architecture/DevelopmentProductionParityTest.php
@@ -11,9 +11,13 @@
 use Componenta\DI\Container;
 use Componenta\DI\ContainerBuilder;
 
-final readonly class DevelopmentProductionParityDependency {}
+final readonly class DevelopmentProductionParityDependency
+{
+}
 
-final readonly class DevelopmentProductionParityInjected {}
+final readonly class DevelopmentProductionParityInjected
+{
+}
 
 #[SetUp('initialize')]
 final class DevelopmentProductionParityEntry
@@ -28,7 +32,8 @@
     public function __construct(
         public DevelopmentProductionParityDependency $dependency,
         public string $value = 'default',
-    ) {}
+    ) {
+    }
 
     public function initialize(DevelopmentProductionParityInjected $injected): void
     {
@@ -40,14 +45,19 @@
 {
     public function __construct(
         public DevelopmentProductionParityDependency $dependency,
-    ) {}
+    ) {
+    }
 }
 
-final readonly class DevelopmentProductionParityInvokable {}
+final readonly class DevelopmentProductionParityInvokable
+{
+}
 
 final readonly class DevelopmentProductionParityExplicit
 {
-    public function __construct(public string $value) {}
+    public function __construct(public string $value)
+    {
+    }
 }
 
 final class DevelopmentProductionParityExplicitFactory

      ----------- end diff -----------

 117) tests/Architecture/CompiledFactoryArchitectureTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Architecture/CompiledFactoryArchitectureTest.php
+++ /home/runner/work/di/di/tests/Architecture/CompiledFactoryArchitectureTest.php
@@ -12,12 +12,16 @@
 use Componenta\DI\ConfigKey;
 use Componenta\DI\ContainerBuilder;
 
-final readonly class CompiledGraphLeafForTest {}
+final readonly class CompiledGraphLeafForTest
+{
+}
 
 #[SetUp('initialize')]
 final class CompiledGraphSetUpForTest
 {
-    public function initialize(CompiledGraphLeafForTest $leaf): void {}
+    public function initialize(CompiledGraphLeafForTest $leaf): void
+    {
+    }
 }
 
 final class CompiledGraphRootForTest
@@ -25,10 +29,14 @@
     #[Inject]
     private CompiledGraphSetUpForTest $setup;
 
-    public function __construct(public CompiledGraphLeafForTest $leaf) {}
+    public function __construct(public CompiledGraphLeafForTest $leaf)
+    {
+    }
 }
 
-final readonly class CompiledFactoryLeafForTest {}
+final readonly class CompiledFactoryLeafForTest
+{
+}
 
 final readonly class CompiledFactoryRootForTest
 {
@@ -35,7 +43,8 @@
     public function __construct(
         public CompiledFactoryLeafForTest $leaf,
         public int $value = 1,
-    ) {}
+    ) {
+    }
 }
 
 it('expands only statically knowable concrete dependencies and honours explicit bindings', function (): void {

      ----------- end diff -----------


Found 117 of 231 files that can be fixed in 1.938 seconds, 90.30 MB memory used
Script php-cs-fixer fix --dry-run --diff handling the cs-check event returned with error code 8
```

## tests

```text
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it wraps a [object, method] array callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws for arrays whose first element is neither object nor string[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it falls back to a plain global function when no service is registered[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws when an instance method is requested but the class is not in the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws when the class in Class::method does not exist[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it returns a Closure as-is[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it returns a callable service from the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws with forMethod variant when the method is missing[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it wraps an invokable object in a first-class callable that forwards to __invoke[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it resolves [class-string, instanceMethod] via container lookup[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it resolves an instance method by fetching the class instance from the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it throws when the container entry is not callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it reports an existing class as a missing service (needs container wiring)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it rejects a non-string method without leaking a native TypeError[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it resolves a static method without consulting the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37munsupported input types[39m[90m → it throws InvalidCallableException for integers[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when the class part does not exist[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when [class-string, method] targets an instance method but the container has no entry[39m

  [30;42;1m PASS [39;49;22m[39m Tests\PublicApiSignatureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementatio[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add attribute handler"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "lazy request factory make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias has"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder compile factories"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable resolver resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable invoker call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "cache generator"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder configure from cache"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias set"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container make"[39m

  [30;42;1m PASS [39;49;22m[39m Tests\MapAttributesTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapHeaders[39m[90m → it extracts single-value headers as plain st[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapQueryString[39m[90m → it extracts the query string parameters into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapServerParams[39m[90m → it returns an empty array when no server params are set[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it treats a null parsed body as an empty array (no merge error)[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestAttributes[39m[90m → it extracts the entire request attribute bag[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapUploadedFiles[39m[90m → it extracts the uploaded-files bag into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapHeaders[39m[90m → it joins multi-value headers with ", "[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapUploadedFiles[39m[90m → it returns an empty array when there are no uploaded files[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapCookies[39m[90m → it extracts the cookie parameters into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it extracts a parsed body array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapQueryString[39m[90m → it returns an empty array when there are no query parameters[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapCookies[39m[90m → it returns an empty array when no cookies are present[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapServerParams[39m[90m → it extracts the server params into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it preserves selected request attributes with explicit null values[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it flattens a parsed body object via get_object_vars[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestAttributes[39m[90m → it returns an empty array when no request attributes are set[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ConfigProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit registers the lazy request resolver factory[39m[90m                      [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionReplacementTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses the latest runtime definition when its resolver kind changes[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableClosureScopeCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps closure parameter metadata isolated by lexical class scope[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\UntypedRequestMappingTest[39m
  [32;1m✓[39;22m[90m [39m[90mit maps an untyped request parameter to an array without invoking the DTO factory[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperPipelineTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it skips cast when the key is absent from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it does not overwrite a null value that is already present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort key is missing from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it maps optional keys when they are present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it throws CasterNotFoundException when the caster is not registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it keeps the value when a mapping keeps the same key name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it exclude runs last, removing entries produced by earlier steps (e.g. defaults)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it renames source keys to target keys, dropping the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it applies defaults to the mapped target key (not the source)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies casts in the order declared (deterministic for multi-key configs)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it fills keys that are absent after mapping[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it silently skips optional source keys marked with "?"[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it drops the listed keys from the final array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it replaces sort/order keys with orderBy via the map lookup[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it always strips the raw sort and order keys from output[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it ignores exclude entries that are not present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it is a no-op when sortMap is empty[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort alias is not in the map[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it throws InvalidArgumentException when a required source key is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it returns data unchanged when no mapping rules are set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it casts against the target key, not the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies the configured caster to the value under the matching key[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ExternalContainerRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it deduplicates repeated registration o[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it returns the first owning container in stable registration order[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it does not expose redundant lookup or iteration APIs[39m

  [30;42;1m PASS [39;49;22m[39m Tests\EntryCacheTest[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it accepts initial base entries without changing null semantics[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it removes base entries explicitly[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it invalidates requested aliases and every sibling of the canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it does not expose the removed duplicate getter API[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it reads base values through the single tryGet API including null[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for union types (intentionally unsupported)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for built-in types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns the class/interface name for non-builtin named types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for a null type[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for untyped parameters[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactorySpecificationValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a deferred service method factory specification[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects malformed factory values during container assembly[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerBuilderTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs default attribute handlers before materializing custom parameter resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses singular validation for every bulk registration API[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it materializes custom pipeline extensions before build returns[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses one proxy collaborator behind reflection and the public container facade[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it shares the built container identity with bootstrap values and factories[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it normalizes duplicate invokable classes from configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it revalidates a trusted cache after a conflicting runtime binding is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it builds one runtime container and resolves fresh objects with explicit context[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects multiple binding mechanisms for the same canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs core pipeline services atomically and forbids rebinding or decoration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects legacy, unknown and malformed dependency configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects unreachable factories and canonical bindings to protected services[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it does not expose removed legacy API[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it omits empty and default dependency sections from normalized cache data[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it keeps local base entries ahead of external containers deterministically[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it breaks mutual external-container has cycles without hiding get failures[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it canonicalizes definitions registered through aliases[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestDataConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly opt into the legacy last-source-wins behavior[39m[90m     [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects a payload value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly preserve the trusted first source[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts the same value repeated by two request sources[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a query value that conflicts with a request attribute[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryArchitectureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit expands only statically knowable concrete dependencies and honours explicit bindings[39m
  [32;1m✓[39;22m[90m [39m[90mit stores compiled autowiring as regular factories and loads shards[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit does not expose the removed generated resolver contract[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CycleGuardTest[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it allows re-entering an id after it has been left[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it exposes the full resolution chain on the cycle exception[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it tolerates leaving an id that was never entered[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it accepts ids that are not currently in-flight[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it throws when the same id is entered twice without leaving[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Cache\DiCacheGeneratorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it overwrites an existing file with new co[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it writes a PHP file that returns the exact input array[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it preserves the file on unwritable targets (throws before corrupting existing contents)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it produces a file with <?php opener and declare(strict_types=1)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it creates intermediate directories as needed[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it throws InvalidConfigurationException when the config contains unserialisable values[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it implements DiCacheGeneratorInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestResolverFactoryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit creates the request resolver without resolving validation services[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\ConfigUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it reads a literal key from the configuration[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it wraps OutOfBoundsException from the extractor into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it falls back to the SetUp key when Config::$path is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it lets PSR-11 container exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it recognises only Config attribute instances[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NestedReferenceDefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves reference definitions recursively inside constructor arrays[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EntryIdUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it fetches the entry from the container using EntryId::$value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it recognises only EntryId instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it propagates NotFoundException when the entry is not registered[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it lets domain exceptions thrown inside the callable propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps PHP engine errors into InvalidCallableException with the original Error as previous[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes the callable and returns its value[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it passes the params list verbatim (no DI, no reordering)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps TypeError from wrongly-typed arguments into InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes an already-valid [object, method] callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes with an empty params list when the callable takes none[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it implements CallableInvokerInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestMapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it lazy-initialises to NullCasterProvider on first read when unset[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies field mapping, defaults and exclude in declaration order[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed attribute when missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies sortMap replacing raw sort/order with orderBy[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mmap configuration merge[39m[90m → it merges class-level map defaults with constructor-supplied map[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies cast via the configured CasterProviderInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed uploaded files by key[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed file key when the file is missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every request attribute[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every uploaded file[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed request attributes by name[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it stores an assigned provider[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestParameterTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → it KEY is the ServerR[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it returns a new array with the request set under the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns the registered request instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns null when the request is absent or invalid[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns true when the KEY entry is a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it overwrites an existing request at the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false when the KEY entry is not a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false for an empty params array[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when a method call i[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when constructor params are configured[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it keeps lazy factory objects intact inside factory definitions[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EnvUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the default when Config/Environment is unavailable[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it reads the variable via the explicit name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it rejects anything that is not an Env instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when variable is missing and no default is declared[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it recognises Env attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it derives the env name from the SetUp key when Env::$name is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the attribute default when the variable is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when environment is unavailable and no default is set[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\DevelopmentProductionParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps development reflection and production compiled containers[39m[90m… [39m [90m0.02s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperSortValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a non-scalar sort alias with a stable mapping exception[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves and caches the validation provider only on first use[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mexternal containers[39m[90m → it delegates get() to an external container that owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the same instance on repeat get() calls (cached)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it applies registered delegators in order to the resolved entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it accepts a DefinitionInterface and resolves it on get()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for an unsupported definition type[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcall()[39m[90m → it invokes the callable with DI-resolved parameters[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcycle detection[39m[90m → it throws CircularDependencyException when factories form a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it resolves aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it invalidates cached results for the alias name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it does not apply delegators registered on the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it invalidates cached resolution when a delegator is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it keeps registered class definition state stable after later fluent changes[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it invalidates a cached entry when set() runs for the same id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it returns a fresh instance on each call (no caching)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it throws NotFoundException for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns false from has() for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it propagates NotFoundException for a string the resolver chain cannot handle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it passes user-supplied params to the constructor by name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it registers an alias that resolves to the target entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the value registered via set()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mself-registration[39m[90m → it exposes itself under every interface it implements[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it resolves aliases transparently[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it reflects a mid-chain update after ca[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mcaching[39m[90m → it invalidates the resolution cache on unset()[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mhas()[39m[90m → it returns true only for registered alias keys, not targets[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37miteration[39m[90m → it reflects later set() calls[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it accepts a cyclic map when skipValidation is true (deferred detection)[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it defensively throws on cycle even when validation was skipped at construction[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it returns the id unchanged when it is not a registered alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for a self-referencing alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it removes the alias from the registry[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mcaching[39m[90m → it invalidates the resolution cache when a link is updated[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37miteration[39m[90m → it yields the alias->target pairs[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it walks the alias chain to the terminal target[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws InvalidConfigurationException for self-referencing alias in the map[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it throws CircularDependencyException when the new mapping would close a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it is a no-op for an id that is not a registered alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws CircularDependencyException for a cycle across the map[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it returns the resolver instance for fluent chaining[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it leaves the map untouched when the update is rejected for a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → it implements AliasResolverInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it registers the alias so it resolves to the target[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it returns the resolver instance for fluent chaining[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it stops chain resolution at the removed link[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompiledFactoryNamespaceTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects invalid generated factory namespaces before writing sour[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\AmbiguousRequestDtoTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects request mapping to more than one possible DTO class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperCollisionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit reads chained mappings from the original input[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects overwriting an unmapped input field[39m
  [32;1m✓[39;22m[90m [39m[90mit supports atomic field swaps[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects two source fields mapped to one target[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps constructor context, injection, setup and no-constructor b[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it throws ResolutionException when a parameter cannot be resolved[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by name[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → it implements CallableExecutorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by position[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it propagates exceptions thrown inside the callable unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mresolve()[39m[90m → it delegates to the underlying CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it invokes a parameterless callable without asking the parameter resolver for anything[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it forwards resolver failures as InvalidCallableException[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DelegatorRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it returns the entry unchanged when no delegators are registered[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it applies delegators in registration order, threading the return value[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a resolution-time foreign exception in DelegatorException[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a delegator's foreign exception in DelegatorException with entry id and previous[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it passes entry and container to the delegator and returns its result[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after invalidate() drops the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after register() invalidates the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses a Closure delegator directly without going through the callable resolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it keeps raw registrations on invalidate(); apply still runs the delegator[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it resolves non-callable registrations via the CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses an already-callable non-Closure delegator directly[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it caches resolved callables across repeated apply() calls[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompositeResolverConstructionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit normalizes named variadic arguments without changing their call[39m[90m… [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects duplicate resolver identities supplied through the constructor[39m
  [32;1m✓[39;22m[90m [39m[90mit preserves resolver order supplied through the constructor[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NullContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it throws NotFoundException on get() regardless of the id[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it includes the requested id in the not-found message[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it implements PSR-11 ContainerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "class FQCN"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "regular id"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "empty string"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it produces a PSR-11 compatible NotFoundExceptionInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactoryResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes methodCalls on the constructed instance in registration order[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is false for unrelated definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true for FactoryDefinition and ClassDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it exposes config to closure factories through the container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it builds an instance from a ClassDefinition with constructor params[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it wraps foreign Throwables from the factory into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves ReferenceDefinition values in ClassDefinition constructor params via the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition throws InvalidConfigurationException for unsupported types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it unwraps FactoryDefinition and invokes the callable inside[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it delegates to LazyServiceFactoryInterface::lazy when the factory implements it[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the second factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mcan()[39m[90m → it reports true only for registered ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves a string-form factory reference through the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the third lazy factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves [string, method] by fetching the object from the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the factory and makes can() return true[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes a closure factory with a container value[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\InvokableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy fact[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition rejects unsupported definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the class, making can() and resolve() succeed[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it instantiates the registered class directly[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it returns an instance of the target class (eager for classes without Lazy/Proxy attributes)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true only for InvokableDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mcan()[39m[90m → it returns true only for registered class ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it produces a fresh instance on each resolve call[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it rejects a concrete proxy override on a class-level Proxy attribute[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsMatchesTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts an integer for a float declaration like PHP does[39m[90m         [39m [90m0.01s[39m  

  [90mTests:[39m    [32;1m318 passed[39;22m[90m (526 assertions)[39m
  [90mDuration:[39m [39m0.42s[39m
  [90mRandom Order Seed:[39m [39m271828[39m

===== seed 161803 =====

  [30;42;1m PASS [39;49;22m[39m Tests\ExternalContainerRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it returns the first owning container in stable registration order[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it does not expose redundant lookup or iteration APIs[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it deduplicates repeated registration of the same instance[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestMapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryStr[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed file key when the file is missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every request attribute[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every uploaded file[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies cast via the configured CasterProviderInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies sortMap replacing raw sort/order with orderBy[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed attribute when missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it stores an assigned provider[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it lazy-initialises to NullCasterProvider on first read when unset[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed uploaded files by key[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies field mapping, defaults and exclude in declaration order[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mmap configuration merge[39m[90m → it merges class-level map defaults with constructor-supplied map[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactoryResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes a closure factory[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is false for unrelated definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves ReferenceDefinition values in ClassDefinition constructor params via the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it unwraps FactoryDefinition and invokes the callable inside[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it exposes config to closure factories through the container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition throws InvalidConfigurationException for unsupported types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it builds an instance from a ClassDefinition with constructor params[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes methodCalls on the constructed instance in registration order[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the second factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the third lazy factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true for FactoryDefinition and ClassDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it delegates to LazyServiceFactoryInterface::lazy when the factory implements it[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves a string-form factory reference through the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the factory and makes can() return true[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it wraps foreign Throwables from the factory into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves [string, method] by fetching the object from the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mcan()[39m[90m → it reports true only for registered ids[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestDataConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a query value that conflicts with a request attribute[39m[90m    [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects a payload value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly preserve the trusted first source[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly opt into the legacy last-source-wins behavior[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts the same value repeated by two request sources[39m

  [30;42;1m PASS [39;49;22m[39m Tests\EntryCacheTest[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it does not expose the removed duplicate getter API[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it accepts initial base entries without changing null semantics[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it reads base values through the single tryGet API including null[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it invalidates requested aliases and every sibling of the canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it removes base entries explicitly[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NestedReferenceDefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves reference definitions recursively inside constructor ar[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Cache\DiCacheGeneratorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it writes a PHP file that returns the exac[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it throws InvalidConfigurationException when the config contains unserialisable values[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it implements DiCacheGeneratorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it creates intermediate directories as needed[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it produces a file with <?php opener and declare(strict_types=1)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it overwrites an existing file with new contents[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it preserves the file on unwritable targets (throws before corrupting existing contents)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverHardeningTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an existing malformed alias cycle during a later update[39m[90m  [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\CallableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it rejects a non-string method without leaking a native TypeError[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it resolves a static method without consulting the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it resolves an instance method by fetching the class instance from the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37munsupported input types[39m[90m → it throws InvalidCallableException for integers[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when [class-string, method] targets an instance method but the container has no entry[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it reports an existing class as a missing service (needs container wiring)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it wraps a [object, method] array callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it rejects arrays that are not exactly length 2[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws when an instance method is requested but the class is not in the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it wraps an invokable object in a first-class callable that forwards to __invoke[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws with forMethod variant when the method is missing[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it returns a callable service from the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it resolves [class-string, staticMethod] without the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it returns a Closure as-is[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when the class part does not exist[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it throws for an unknown string that is neither service nor function nor class[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when the method does not exist on the object[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it falls back to a plain global function when no service is registered[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws for arrays whose first element is neither object nor string[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws when the class in Class::method does not exist[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it throws when the container entry is not callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it resolves [class-string, instanceMethod] via container lookup[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryArchitectureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit stores compiled autowiring as regular factories and loads shards[39m[90m…[39m [90m0.02s[39m  
  [32;1m✓[39;22m[90m [39m[90mit does not expose the removed generated resolver contract[39m
  [32;1m✓[39;22m[90m [39m[90mit expands only statically knowable concrete dependencies and honours explicit bindings[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompositeResolverConstructionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit normalizes named variadic arguments without changing their call order[39m
  [32;1m✓[39;22m[90m [39m[90mit preserves resolver order supplied through the constructor[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects duplicate resolver identities supplied through the constructor[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\AmbiguousRequestDtoTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects request mapping to more than one possible DTO class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\PublicApiSignatureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add parameter resolver"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable invoker call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder configure from cache"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias has"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "cache generator"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder compile factories"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add attribute handler"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "lazy request factory make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable resolver resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias set"[39m

  [30;42;1m PASS [39;49;22m[39m Tests\InvokableAliasConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a keyed invokable that conflicts with an existing alias[39m[90m  [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects a conflicting fluent invokable alias registration[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a keyed invokable when an existing alias resolves to the same target[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it propagates exceptions thrown inside the callable unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mresolve()[39m[90m → it delegates to the underlying CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → it implements CallableExecutorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by name[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it invokes a parameterless callable without asking the parameter resolver for anything[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it forwards resolver failures as InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it throws ResolutionException when a parameter cannot be resolved[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by position[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\CompositeResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it reports can()=false when no resolvers are registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it negative-caches misses so a subsequent has()+resolve() does not re-scan[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it delegates to the first resolver that claims the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it forwards setDefinition to the first supporting resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it caches the owner so a later resolve() does not re-scan can() on other resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it passes the context through to the owning resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it returns false from supportsDefinition when no child is definition-aware[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it invalidates the owner cache when a resolver is added[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it throws NotFoundException on resolve() when no resolver owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it throws InvalidConfigurationException when no resolver supports the definition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it invalidates the owner cache when a definition is set[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EntryIdUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it propagates NotFoundException when the entry is not registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it fetches the entry from the container using EntryId::$value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it recognises only EntryId instances[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit does not conflate different closure parameter signatures[39m
  [32;1m✓[39;22m[90m [39m[90mit reuses parameter targets across fresh closures from the same source signature[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ConfigProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit registers the lazy request resolver factory[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompiledFactoryNamespaceTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects invalid generated factory namespaces before writing source[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionReplacementTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses the latest runtime definition when its resolver kind changes[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it keeps lazy factory objects intact inside factory definitions[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when constructor params are configured[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when a method call is configured[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for built-in types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for union types (intentionally unsupported)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for a null type[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for untyped parameters[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns the class/interface name for non-builtin named types[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DelegatorRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a resolution-time foreign exception in DelegatorException[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it resolves non-callable registrations via the CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a delegator's foreign exception in DelegatorException with entry id and previous[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses an already-callable non-Closure delegator directly[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it caches resolved callables across repeated apply() calls[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it applies delegators in registration order, threading the return value[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after invalidate() drops the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it passes entry and container to the delegator and returns its result[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses a Closure delegator directly without going through the callable resolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after register() invalidates the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it returns the entry unchanged when no delegators are registered[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it keeps raw registrations on invalidate(); apply still runs the delegator[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\UntypedRequestMappingTest[39m
  [32;1m✓[39;22m[90m [39m[90mit maps an untyped request parameter to an array without invoking t[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\CallableClosureScopeCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps closure parameter metadata isolated by lexical class scope[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsMatchesTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts an integer for a float declaration like PHP does[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestResolverFactoryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit creates the request resolver without resolving validation services[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperCollisionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects two source fields mapped to one target[39m
  [32;1m✓[39;22m[90m [39m[90mit reads chained mappings from the original input[39m
  [32;1m✓[39;22m[90m [39m[90mit supports atomic field swaps[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects overwriting an unmapped input field[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperPipelineTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it drops the listed keys from the final array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it silently skips optional source keys marked with "?"[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it always strips the raw sort and order keys from output[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it casts against the target key, not the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it replaces sort/order keys with orderBy via the map lookup[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it skips cast when the key is absent from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies the configured caster to the value under the matching key[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort alias is not in the map[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it maps optional keys when they are present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it throws CasterNotFoundException when the caster is not registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it applies defaults to the mapped target key (not the source)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it ignores exclude entries that are not present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it throws InvalidArgumentException when a required source key is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort key is missing from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it fills keys that are absent after mapping[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it keeps the value when a mapping keeps the same key name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies casts in the order declared (deterministic for multi-key configs)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it does not overwrite a null value that is already present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it is a no-op when sortMap is empty[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it renames source keys to target keys, dropping the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it exclude runs last, removing entries produced by earlier steps (e.g. defaults)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it returns data unchanged when no mapping rules are set[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EnvUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws Resolution[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it recognises Env attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the default when Config/Environment is unavailable[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it rejects anything that is not an Env instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it derives the env name from the SetUp key when Env::$name is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the attribute default when the variable is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when environment is unavailable and no default is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it reads the variable via the explicit name[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws InvalidConfigura[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mhas()[39m[90m → it returns true only for registered alias keys, not targets[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it throws CircularDependencyException when the new mapping would close a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it walks the alias chain to the terminal target[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it leaves the map untouched when the update is rejected for a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mcaching[39m[90m → it invalidates the resolution cache on unset()[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it defensively throws on cycle even when validation was skipped at construction[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37miteration[39m[90m → it yields the alias->target pairs[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it removes the alias from the registry[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it registers the alias so it resolves to the target[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it is a no-op for an id that is not a registered alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it reflects a mid-chain update after calling set()[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it returns the resolver instance for fluent chaining[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37miteration[39m[90m → it reflects later set() calls[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → it implements AliasResolverInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it returns the id unchanged when it is not a registered alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for a self-referencing alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it returns the resolver instance for fluent chaining[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mcaching[39m[90m → it invalidates the resolution cache when a link is updated[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws CircularDependencyException for a cycle across the map[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it stops chain resolution at the removed link[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it accepts a cyclic map when skipValidation is true (deferred detection)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\DevelopmentProductionParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps development reflection and production compiled containers[39m[90m… [39m [90m0.02s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves and caches the validation provider only on first use[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps constructor context, injection, setup and no-constructor b[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperSortValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a non-scalar sort alias with a stable mapping exception[39m

  [30;42;1m PASS [39;49;22m[39m Tests\MapAttributesTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it treats a null parsed body as an empty array (no merge error)[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapServerParams[39m[90m → it extracts the server params into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapCookies[39m[90m → it extracts the cookie parameters into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapHeaders[39m[90m → it joins multi-value headers with ", "[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it extracts a parsed body array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapQueryString[39m[90m → it returns an empty array when there are no query parameters[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapServerParams[39m[90m → it returns an empty array when no server params are set[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it preserves selected request attributes with explicit null values[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapHeaders[39m[90m → it extracts single-value headers as plain strings[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapUploadedFiles[39m[90m → it extracts the uploaded-files bag into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapCookies[39m[90m → it returns an empty array when no cookies are present[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapUploadedFiles[39m[90m → it returns an empty array when there are no uploaded files[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it flattens a parsed body object via get_object_vars[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapQueryString[39m[90m → it extracts the query string parameters into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestAttributes[39m[90m → it extracts the entire request attribute bag[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestAttributes[39m[90m → it returns an empty array when no request attributes are set[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NullContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it implements PSR-11 ContainerInterface[39m[90m             [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it produces a PSR-11 compatible NotFoundExceptionInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it throws NotFoundException on get() regardless of the id[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "empty string"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "regular id"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "class FQCN"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it includes the requested id in the not-found message[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes an already-valid [object, method] callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps PHP engine errors into InvalidCallableException with the original Error as previous[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it implements CallableInvokerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes the callable and returns its value[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps TypeError from wrongly-typed arguments into InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it lets domain exceptions thrown inside the callable propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes with an empty params list when the callable takes none[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it passes the params list verbatim (no DI, no reordering)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CycleGuardTest[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it throws when the same id is entered twice without leaving[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it exposes the full resolution chain on the cycle exception[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it tolerates leaving an id that was never entered[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it accepts ids that are not currently in-flight[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it allows re-entering an id after it has been left[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestParameterTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns the registered request instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → it KEY is the ServerRequestInterface FQN so provided-params carry the contract identity[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it overwrites an existing request at the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false when the KEY entry is not a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it returns a new array with the request set under the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns true when the KEY entry is a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns null when the request is absent or invalid[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false for an empty params array[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerBuilderTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects legacy, unknown and malformed dependency configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it does not expose removed legacy API[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it canonicalizes definitions registered through aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects unreachable factories and canonical bindings to protected services[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it materializes custom pipeline extensions before build returns[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it breaks mutual external-container has cycles without hiding get failures[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it normalizes duplicate invokable classes from configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs core pipeline services atomically and forbids rebinding or decoration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it builds one runtime container and resolves fresh objects with explicit context[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects multiple binding mechanisms for the same canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it shares the built container identity with bootstrap values and factories[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses one proxy collaborator behind reflection and the public container facade[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs default attribute handlers before materializing custom parameter resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it keeps local base entries ahead of external containers deterministically[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it revalidates a trusted cache after a conflicting runtime binding is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it omits empty and default dependency sections from normalized cache data[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses singular validation for every bulk registration API[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderRetryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit retries validation provider lookup after a transient failure[39m[90m     [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\InvokableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it handles classes without a constructor (avoids calling __construct on null)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it produces a fresh instance on each resolve call[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it returns an instance of the target class (eager for classes without Lazy/Proxy attributes)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true only for InvokableDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it instantiates the registered class directly[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the class, making can() and resolve() succeed[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition rejects unsupported definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mcan()[39m[90m → it returns true only for registered class ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it rejects a concrete proxy override on a class-level Proxy attribute[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\ConfigUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it reads a literal key from the configuration[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it lets PSR-11 container exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it falls back to the SetUp key when Config::$path is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it recognises only Config attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it wraps OutOfBoundsException from the extractor into ResolutionException[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ProxyInjectionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses an explicit concrete class for interface-typed virtual proxies[39m
  [32;1m✓[39;22m[90m [39m[90mit separates an arbitrary service id from its concrete proxy class[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an interface proxy when no concrete class can be inferred[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for an unsupported definition type[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mexternal containers[39m[90m → it delegates get() to an external container that owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mself-registration[39m[90m → it exposes itself under every interface it implements[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it invalidates cached results for the alias name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it resolves aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it registers an alias that resolves to the target entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it resolves aliases transparently[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it keeps registered class definition state stable after later fluent changes[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns false from has() for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it passes user-supplied params to the constructor by name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it throws NotFoundException for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it applies registered delegators in order to the resolved entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it invalidates a cached entry when set() runs for the same id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it propagates NotFoundException for a string the resolver chain cannot handle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it invalidates cached resolution when a delegator is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the same instance on repeat get() calls (cached)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcycle detection[39m[90m → it throws CircularDependencyException when factories form a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcall()[39m[90m → it invokes the callable with DI-resolved parameters[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it returns a fresh instance on each call (no caching)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the value registered via set()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it accepts a DefinitionInterface and resolves it on get()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it does not apply delegators registered on the id[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactorySpecificationValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a deferred service method factory specification[39m[90m          [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects malformed factory values during container assembly[39m

  [90mTests:[39m    [32;1m318 passed[39;22m[90m (526 assertions)[39m
  [90mDuration:[39m [39m0.42s[39m
  [90mRandom Order Seed:[39m [39m161803[39m

```
