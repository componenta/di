# Independent dev recheck

Commit checked: 0650d9991c180091f2a95ad2c3da3929357bf598

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
Note: Using configuration file /home/runner/work/di/di/phpstan.neon.dist.
```

## cs-check

```text
 

      ----------- end diff -----------

  52) tests/ProxyInjectionTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/ProxyInjectionTest.php
+++ /home/runner/work/di/di/tests/ProxyInjectionTest.php
@@ -81,7 +81,7 @@
         )
         ->build();
 
-    expect(fn () => $container->make(AmbiguousInterfaceProxyConsumer::class))
+    expect(fn() => $container->make(AmbiguousInterfaceProxyConsumer::class))
         ->toThrow(
             ResolutionException::class,
             'specify #[Proxy(ConcreteClass::class)]',

      ----------- end diff -----------

  53) tests/Cache/DiCacheGeneratorTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Cache/DiCacheGeneratorTest.php
+++ /home/runner/work/di/di/tests/Cache/DiCacheGeneratorTest.php
@@ -100,9 +100,9 @@
     it('throws InvalidConfigurationException when the config contains unserialisable values', function () {
         $generator = new DiCacheGenerator();
         // Closures cannot be serialised to PHP source by Export::pretty().
-        $config = ['factory' => fn () => 'unserialisable'];
+        $config = ['factory' => fn() => 'unserialisable'];
 
-        expect(fn () => $generator->generate($config, $this->path))
+        expect(fn() => $generator->generate($config, $this->path))
             ->toThrow(InvalidConfigurationException::class);
     });
 });

      ----------- end diff -----------

  54) tests/DefinitionReplacementTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/DefinitionReplacementTest.php
+++ /home/runner/work/di/di/tests/DefinitionReplacementTest.php
@@ -11,7 +11,7 @@
 it('uses the latest runtime definition when its resolver kind changes', function (): void {
     $container = minimalContainer();
 
-    $container->set('service', Definition::factory(static fn () => 'from-factory'));
+    $container->set('service', Definition::factory(static fn() => 'from-factory'));
     expect($container->get('service'))->toBe('from-factory');
 
     $container->set('service', Definition::invokable(ReplacementInvokableService::class));

      ----------- end diff -----------

  55) tests/Resolver/FactorySpecificationValidationTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/FactorySpecificationValidationTest.php
+++ /home/runner/work/di/di/tests/Resolver/FactorySpecificationValidationTest.php
@@ -18,7 +18,7 @@
         ],
     ]);
 
-    expect(fn () => ContainerBuilder::configure($config)->build())
+    expect(fn() => ContainerBuilder::configure($config)->build())
         ->toThrow(InvalidConfigurationException::class, 'Factory "invalid.factory"');
 });
 
@@ -32,7 +32,7 @@
         ],
     ]);
 
-    expect(fn () => ContainerBuilder::configure($config)->build())
+    expect(fn() => ContainerBuilder::configure($config)->build())
         ->toThrow(InvalidConfigurationException::class, 'Factory "invalid.object.factory"');
 });
 
@@ -52,7 +52,7 @@
 it('rejects an incomplete compiled definition registered at runtime', function (): void {
     $container = (new ContainerBuilder())->build();
 
-    expect(fn () => $container->set(
+    expect(fn() => $container->set(
         'invalid.compiled',
         new CompiledFactoryDefinition('', '', ''),
     ))->toThrow(InvalidConfigurationException::class);

      ----------- end diff -----------

  56) tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
+++ /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
@@ -46,7 +46,7 @@
     it('propagates NotFoundException when the entry is not registered', function () {
         $unwrapper = new EntryIdUnwrapper(entryIdUnwrapperContainer([]));
 
-        expect(fn () => $unwrapper->unwrap(new EntryId('absent'), 'k'))
+        expect(fn() => $unwrapper->unwrap(new EntryId('absent'), 'k'))
             ->toThrow(NotFoundException::class);
     });
 });

      ----------- end diff -----------

  57) tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
+++ /home/runner/work/di/di/tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
@@ -68,7 +68,7 @@
     it('lets PSR-11 container exceptions propagate unchanged', function () {
         $unwrapper = new ConfigUnwrapper(configUnwrapperContainer(NotFoundException::forService(Config::KEY)));
 
-        expect(fn () => $unwrapper->unwrap(new Config('k'), 'key'))
+        expect(fn() => $unwrapper->unwrap(new Config('k'), 'key'))
             ->toThrow(NotFoundException::class);
     });
 });

      ----------- end diff -----------

  58) tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
+++ /home/runner/work/di/di/tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
@@ -71,7 +71,7 @@
         it('throws ResolutionException when variable is missing and no default is declared', function () {
             $unwrapper = new EnvUnwrapper(configContainerForEnv(new Environment([])));
 
-            expect(fn () => $unwrapper->unwrap(new Env('ABSENT'), 'key'))
+            expect(fn() => $unwrapper->unwrap(new Env('ABSENT'), 'key'))
                 ->toThrow(ResolutionException::class, 'ABSENT');
         });
 
@@ -84,7 +84,7 @@
         it('throws ResolutionException when environment is unavailable and no default is set', function () {
             $unwrapper = new EnvUnwrapper(configContainerForEnv(null));
 
-            expect(fn () => $unwrapper->unwrap(new Env('X'), 'key'))
+            expect(fn() => $unwrapper->unwrap(new Env('X'), 'key'))
                 ->toThrow(ResolutionException::class);
         });
     });

      ----------- end diff -----------

  59) tests/Resolver/CompositeResolverTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Resolver/CompositeResolverTest.php
+++ /home/runner/work/di/di/tests/Resolver/CompositeResolverTest.php
@@ -70,7 +70,7 @@
         $composite = new CompositeResolver();
         $composite->addResolver(entryResolver([]));
 
-        expect(fn () => $composite->resolve('missing'))
+        expect(fn() => $composite->resolve('missing'))
             ->toThrow(NotFoundException::class);
     });
 
@@ -90,7 +90,10 @@
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
@@ -153,7 +156,7 @@
             $composite = new CompositeResolver();
             $composite->addResolver(entryResolver([]));
 
-            expect($composite->supportsDefinition(new FactoryDefinition(fn () => null)))->toBeFalse();
+            expect($composite->supportsDefinition(new FactoryDefinition(fn() => null)))->toBeFalse();
         });
 
         it('forwards setDefinition to the first supporting resolver', function () {
@@ -163,7 +166,7 @@
             $composite->addResolver($plain);
             $composite->addResolver($aware);
 
-            $definition = new FactoryDefinition(fn () => 'value');
+            $definition = new FactoryDefinition(fn() => 'value');
             $composite->setDefinition('svc', $definition);
 
             expect($aware->definitions)->toBe(['svc' => $definition]);
@@ -176,7 +179,7 @@
             $composite = new CompositeResolver();
             $composite->addResolver(definitionAwareResolver(supported: [FactoryDefinition::class]));
 
-            expect(fn () => $composite->setDefinition('svc', $unsupportedDefinition))
+            expect(fn() => $composite->setDefinition('svc', $unsupportedDefinition))
                 ->toThrow(InvalidConfigurationException::class);
         });
 
@@ -186,7 +189,7 @@
             $composite->addResolver($aware);
 
             expect($composite->can('svc'))->toBeFalse();
-            $composite->setDefinition('svc', new FactoryDefinition(fn () => 'v'));
+            $composite->setDefinition('svc', new FactoryDefinition(fn() => 'v'));
 
             expect($composite->can('svc'))->toBeTrue();
         });

      ----------- end diff -----------

  60) tests/Resolver/FactoryResolverTest.php
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
@@ -54,7 +54,7 @@
 describe('Resolver\\FactoryResolver', function () {
     describe('can()', function () {
         it('reports true only for registered ids', function () {
-            $resolver = makeFactoryResolver(['svc' => fn () => 'v']);
+            $resolver = makeFactoryResolver(['svc' => fn() => 'v']);
 
             expect($resolver->can('svc'))->toBeTrue()
                 ->and($resolver->can('missing'))->toBeFalse();
@@ -65,7 +65,7 @@
         it('invokes a closure factory with a container value', function () {
             $container = smallContainer();
             $resolver = makeFactoryResolver([
-                'svc' => fn (ContainerInterface $c) => [$c, 'produced'],
+                'svc' => fn(ContainerInterface $c) => [$c, 'produced'],
             ], container: $container);
 
             $result = $resolver->resolve('svc');
@@ -78,7 +78,7 @@
         it('exposes config to closure factories through the container value', function () {
             $config = new Config(['app' => ['name' => 'Componenta']]);
             $resolver = makeFactoryResolver([
-                'svc' => fn (ContainerValue $container): string => $container->config->string(new \Componenta\Config\ConfigPath('app.name')),
+                'svc' => fn(ContainerValue $container): string => $container->config->string(new \Componenta\Config\ConfigPath('app.name')),
             ], container: smallContainer([
                 Config::class => $config,
             ]));
@@ -88,7 +88,7 @@
 
         it('passes resolution context as the second factory argument', function () {
             $resolver = makeFactoryResolver([
-                'svc' => fn (ContainerInterface $container, array $context): array => [
+                'svc' => fn(ContainerInterface $container, array $context): array => [
                     'context' => $context,
                     'container' => $container,
                 ],
@@ -102,7 +102,7 @@
 
         it('unwraps FactoryDefinition and invokes the callable inside', function () {
             $resolver = makeFactoryResolver([
-                'svc' => Definition::factory(fn () => 'from-definition'),
+                'svc' => Definition::factory(fn() => 'from-definition'),
             ]);
 
             expect($resolver->resolve('svc'))->toBe('from-definition');
@@ -151,10 +151,10 @@
         });
 
         it('resolves a string-form factory reference through the container', function () {
-            $callable = fn () => 'produced';
+            $callable = fn() => 'produced';
             $resolver = makeFactoryResolver(
                 ['svc' => 'factory.id'],
-                container: smallContainer(['factory.id' => fn () => $callable]),
+                container: smallContainer(['factory.id' => fn() => $callable]),
             );
 
             expect($resolver->resolve('svc'))->toBe('produced');
@@ -169,7 +169,7 @@
             };
             $resolver = makeFactoryResolver(
                 ['svc' => [$service::class, 'make']],
-                container: smallContainer([$service::class => fn () => $service]),
+                container: smallContainer([$service::class => fn() => $service]),
             );
 
             expect($resolver->resolve('svc'))->toBe('made');
@@ -236,7 +236,9 @@
         it('wraps foreign Throwables from the factory into ResolutionException', function () {
             $boom = new RuntimeException('factory boom');
             $resolver = makeFactoryResolver([
-                'svc' => function () use ($boom) { throw $boom; },
+                'svc' => function () use ($boom) {
+                    throw $boom;
+                },
             ]);
 
             try {
@@ -252,7 +254,9 @@
         it('lets ContainerExceptionInterface exceptions propagate unchanged', function () {
             $original = NotFoundException::forService('inner');
             $resolver = makeFactoryResolver([
-                'svc' => function () use ($original) { throw $original; },
+                'svc' => function () use ($original) {
+                    throw $original;
+                },
             ]);
 
             try {
@@ -270,7 +274,7 @@
         it('supportsDefinition is true for FactoryDefinition and ClassDefinition', function () {
             $resolver = makeFactoryResolver([]);
 
-            expect($resolver->supportsDefinition(new FactoryDefinition(fn () => null)))->toBeTrue()
+            expect($resolver->supportsDefinition(new FactoryDefinition(fn() => null)))->toBeTrue()
                 ->and($resolver->supportsDefinition(new ClassDefinition(SimpleService::class)))->toBeTrue();
         });
 
@@ -284,7 +288,7 @@
         it('setDefinition registers the factory and makes can() return true', function () {
             $resolver = makeFactoryResolver([]);
 
-            $resolver->setDefinition('svc', new FactoryDefinition(fn () => 'v'));
+            $resolver->setDefinition('svc', new FactoryDefinition(fn() => 'v'));
 
             expect($resolver->can('svc'))->toBeTrue()
                 ->and($resolver->resolve('svc'))->toBe('v');
@@ -293,7 +297,7 @@
         it('setDefinition throws InvalidConfigurationException for unsupported types', function () {
             $resolver = makeFactoryResolver([]);
 
-            expect(fn () => $resolver->setDefinition('svc', new InvokableDefinition(SimpleService::class)))
+            expect(fn() => $resolver->setDefinition('svc', new InvokableDefinition(SimpleService::class)))
                 ->toThrow(InvalidConfigurationException::class);
         });
     });

      ----------- end diff -----------

  61) benchmarks/GeneratedVsReflectionBench.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/benchmarks/GeneratedVsReflectionBench.php
+++ /home/runner/work/di/di/benchmarks/GeneratedVsReflectionBench.php
@@ -68,19 +68,19 @@
     $compiledBuildMilliseconds = (hrtime(true) - $compiledBuildStarted) / 1_000_000;
 
     $reflectionDefault = benchmark(
-        static fn (): object => $reflection->make(BenchmarkEntry::class),
+        static fn(): object => $reflection->make(BenchmarkEntry::class),
         $iterations,
     );
     $compiledDefault = benchmark(
-        static fn (): object => $compiled->make(BenchmarkEntry::class),
+        static fn(): object => $compiled->make(BenchmarkEntry::class),
         $iterations,
     );
     $reflectionOverride = benchmark(
-        static fn (): object => $reflection->make(BenchmarkEntry::class, ['number' => 42]),
+        static fn(): object => $reflection->make(BenchmarkEntry::class, ['number' => 42]),
         $iterations,
     );
     $compiledOverride = benchmark(
-        static fn (): object => $compiled->make(BenchmarkEntry::class, ['number' => 42]),
+        static fn(): object => $compiled->make(BenchmarkEntry::class, ['number' => 42]),
         $iterations,
     );
 

      ----------- end diff -----------

  62) tests/Architecture/DevelopmentProductionParityTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Architecture/DevelopmentProductionParityTest.php
+++ /home/runner/work/di/di/tests/Architecture/DevelopmentProductionParityTest.php
@@ -139,7 +139,7 @@
         'null-present' => $container->has('parity.null'),
         'null-value' => $container->get('parity.null'),
         'call-result' => $container->call(
-            static fn (
+            static fn(
                 DevelopmentProductionParityDependency $dependency,
                 string $value = 'fallback',
             ): string => $dependency::class . ':' . $value,

      ----------- end diff -----------

  63) tests/Architecture/CompiledFactoryArchitectureTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/Architecture/CompiledFactoryArchitectureTest.php
+++ /home/runner/work/di/di/tests/Architecture/CompiledFactoryArchitectureTest.php
@@ -76,7 +76,7 @@
         ])->not->toHaveKey(CompiledFactoryLeafForTest::class)
             ->and($builder->invokables)->toContain(CompiledFactoryLeafForTest::class)
             ->and(array_unique(array_map(
-                static fn (CompiledFactoryDefinition $factory): string => $factory->file,
+                static fn(CompiledFactoryDefinition $factory): string => $factory->file,
                 $factories,
             )))->toHaveCount(1)
             ->and($source)->toBeString()->not->toContain('if (!class_exists(')

      ----------- end diff -----------

  64) tests/CallableResolverTest.php
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
@@ -35,7 +36,7 @@
 describe('CallableResolver', function () {
     describe('closures and callable objects', function () {
         it('returns a Closure as-is', function () {
-            $closure = fn () => 'ok';
+            $closure = fn() => 'ok';
 
             $resolver = new CallableResolver(new NullContainer());
 
@@ -80,7 +81,7 @@
         it('throws when the class in Class::method does not exist', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve('NoSuchClass::someMethod'))
+            expect(fn() => $resolver->resolve('NoSuchClass::someMethod'))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -87,7 +88,7 @@
         it('throws with forMethod variant when the method is missing', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve(ServiceWithMethods::class . '::missing'))
+            expect(fn() => $resolver->resolve(ServiceWithMethods::class . '::missing'))
                 ->toThrow(InvalidCallableException::class, 'missing');
         });
 
@@ -94,7 +95,7 @@
         it('throws when an instance method is requested but the class is not in the container', function () {
             $resolver = new CallableResolver(mapContainer([]));
 
-            expect(fn () => $resolver->resolve(ServiceWithMethods::class . '::instanceMethod'))
+            expect(fn() => $resolver->resolve(ServiceWithMethods::class . '::instanceMethod'))
                 ->toThrow(InvalidCallableException::class);
         });
     });
@@ -112,7 +113,7 @@
         it('throws when the container entry is not callable', function () {
             $resolver = new CallableResolver(mapContainer(['plain' => new NonInvokableService()]));
 
-            expect(fn () => $resolver->resolve('plain'))
+            expect(fn() => $resolver->resolve('plain'))
                 ->toThrow(InvalidCallableException::class, 'not invokable');
         });
 
@@ -127,7 +128,7 @@
         it('reports an existing class as a missing service (needs container wiring)', function () {
             $resolver = new CallableResolver(mapContainer([]));
 
-            expect(fn () => $resolver->resolve(InvokableService::class))
+            expect(fn() => $resolver->resolve(InvokableService::class))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -134,7 +135,7 @@
         it('throws for an unknown string that is neither service nor function nor class', function () {
             $resolver = new CallableResolver(mapContainer([]));
 
-            expect(fn () => $resolver->resolve('totally.unknown.token'))
+            expect(fn() => $resolver->resolve('totally.unknown.token'))
                 ->toThrow(InvalidCallableException::class);
         });
     });
@@ -143,7 +144,7 @@
         it('rejects arrays that are not exactly length 2', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve([ServiceWithMethods::class]))
+            expect(fn() => $resolver->resolve([ServiceWithMethods::class]))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -150,7 +151,7 @@
         it('rejects a non-string method without leaking a native TypeError', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve([ServiceWithMethods::class, 123]))
+            expect(fn() => $resolver->resolve([ServiceWithMethods::class, 123]))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -174,7 +175,7 @@
         it('throws when [class-string, method] targets an instance method but the container has no entry', function () {
             $resolver = new CallableResolver(mapContainer([]));
 
-            expect(fn () => $resolver->resolve([ServiceWithMethods::class, 'instanceMethod']))
+            expect(fn() => $resolver->resolve([ServiceWithMethods::class, 'instanceMethod']))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -181,7 +182,7 @@
         it('throws when the class part does not exist', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve(['NoSuchClass', 'someMethod']))
+            expect(fn() => $resolver->resolve(['NoSuchClass', 'someMethod']))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -188,7 +189,7 @@
         it('throws when the method does not exist on the object', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve([new ServiceWithMethods(), 'nope']))
+            expect(fn() => $resolver->resolve([new ServiceWithMethods(), 'nope']))
                 ->toThrow(InvalidCallableException::class);
         });
 
@@ -195,7 +196,7 @@
         it('throws for arrays whose first element is neither object nor string', function () {
             $resolver = new CallableResolver(new NullContainer());
 
-            expect(fn () => $resolver->resolve([42, 'foo']))
+            expect(fn() => $resolver->resolve([42, 'foo']))
                 ->toThrow(InvalidCallableException::class);
         });
     });
@@ -202,7 +203,7 @@
 
     describe('unsupported input types', function () {
         it('throws InvalidCallableException for integers', function () {
-            expect(fn () => (new CallableResolver(new NullContainer()))->resolve(123))
+            expect(fn() => (new CallableResolver(new NullContainer()))->resolve(123))
                 ->toThrow(InvalidCallableException::class);
         });
     });

      ----------- end diff -----------

  65) tests/CompositeResolverConstructionTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/CompositeResolverConstructionTest.php
+++ /home/runner/work/di/di/tests/CompositeResolverConstructionTest.php
@@ -45,6 +45,6 @@
 it('rejects duplicate resolver identities supplied through the constructor', function () {
     $resolver = new ConstructorEntryResolverForTest('entry', 'value');
 
-    expect(fn () => new CompositeResolver($resolver, $resolver))
+    expect(fn() => new CompositeResolver($resolver, $resolver))
         ->toThrow(InvalidArgumentException::class);
 });

      ----------- end diff -----------

  66) tests/CycleGuardTest.php
      ---------- begin diff ----------
--- /home/runner/work/di/di/tests/CycleGuardTest.php
+++ /home/runner/work/di/di/tests/CycleGuardTest.php
@@ -20,7 +20,7 @@
             $guard = new CycleGuard();
             $guard->enter('a');
 
-            expect(fn () => $guard->enter('a'))
+            expect(fn() => $guard->enter('a'))
                 ->toThrow(CircularDependencyException::class);
         });
 
@@ -44,7 +44,7 @@
             $guard->enter('a');
             $guard->leave('a');
 
-            expect(fn () => $guard->enter('a'))
+            expect(fn() => $guard->enter('a'))
                 ->not->toThrow(CircularDependencyException::class);
         });
 
@@ -51,7 +51,7 @@
         it('tolerates leaving an id that was never entered', function () {
             $guard = new CycleGuard();
 
-            expect(fn () => $guard->leave('never-entered'))
+            expect(fn() => $guard->leave('never-entered'))
                 ->not->toThrow(Throwable::class);
         });
     });

      ----------- end diff -----------


Found 66 of 234 files that can be fixed in 1.621 seconds, 92.00 MB memory used
Script php-cs-fixer fix --dry-run --diff handling the cs-check event returned with error code 8
```

## tests

```text
  [32;1m✓[39;22m[90m [39m[90mit reports a missing proxy target as a ReflectionException[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EnvUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it recognises Env attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it reads the variable via the explicit name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the default when Config/Environment is unavailable[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it derives the env name from the SetUp key when Env::$name is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when environment is unavailable and no default is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the attribute default when the variable is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when variable is missing and no default is declared[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it rejects anything that is not an Env instance[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderRetryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit retries validation provider lookup after a transient failure[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompositeResolverConstructionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit normalizes named variadic arguments without changing their call order[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects duplicate resolver identities supplied through the constructor[39m
  [32;1m✓[39;22m[90m [39m[90mit preserves resolver order supplied through the constructor[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ConfigProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit registers the lazy request resolver factory[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it resolves [class-string, staticMethod] without the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when the method does not exist on the object[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it throws for an unknown string that is neither service nor function nor class[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it rejects arrays that are not exactly length 2[39m
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

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerInvalidTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a non-callable value before invoking the PHP engine[39m[90m      [39m [90m0.01s[39m  

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

  [30;42;1m PASS [39;49;22m[39m Tests\InvokableAliasConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a keyed invokable that conflicts with an existing alias[39m[90m  [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects a conflicting fluent invokable alias registration[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a keyed invokable when an existing alias resolves to the same target[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CycleGuardTest[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it allows re-entering an id after it has been left[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it exposes the full resolution chain on the cycle exception[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it tolerates leaving an id that was never entered[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it accepts ids that are not currently in-flight[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it throws when the same id is entered twice without leaving[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EntryIdUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it recognises only EntryId instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it propagates NotFoundException when the entry is not registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it fetches the entry from the container using EntryId::$value[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestResolverFactoryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit creates the request resolver without resolving validation services[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsMatchesTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts an integer for a float declaration like PHP does[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactoryResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it builds an instance from a ClassDefinition with constructor params[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes methodCalls on the constructed instance in registration order[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the third lazy factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mcan()[39m[90m → it reports true only for registered ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it unwraps FactoryDefinition and invokes the callable inside[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition throws InvalidConfigurationException for unsupported types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it wraps foreign Throwables from the factory into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it exposes config to closure factories through the container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes a closure factory with a container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it delegates to LazyServiceFactoryInterface::lazy when the factory implements it[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is false for unrelated definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves a string-form factory reference through the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true for FactoryDefinition and ClassDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves ReferenceDefinition values in ClassDefinition constructor params via the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the second factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves [string, method] by fetching the object from the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the factory and makes can() return true[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NestedReferenceDefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves reference definitions recursively inside constructor ar[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestParameterTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns true when the KEY entry is a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it returns a new array with the request set under the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false for an empty params array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it overwrites an existing request at the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false when the KEY entry is not a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns null when the request is absent or invalid[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → it KEY is the ServerRequestInterface FQN so provided-params carry the contract identity[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns the registered request instance[39m

  [30;42;1m PASS [39;49;22m[39m Tests\EntryCacheTest[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it accepts initial base entries without changing null semantics[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it removes base entries explicitly[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it invalidates requested aliases and every sibling of the canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it does not expose the removed duplicate getter API[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it reads base values through the single tryGet API including null[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit reuses parameter targets across fresh closures from the same source signature[39m
  [32;1m✓[39;22m[90m [39m[90mit does not conflate different closure parameter signatures[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NullContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it throws NotFoundException on get() regardless of the id[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it includes the requested id in the not-found message[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it implements PSR-11 ContainerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "class FQCN"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "regular id"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "empty string"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it produces a PSR-11 compatible NotFoundExceptionInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryArchitectureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit expands only statically knowable concrete dependencies and honours explicit bindings[39m
  [32;1m✓[39;22m[90m [39m[90mit stores compiled autowiring as regular factories and loads shards[39m[90m…[39m [90m0.02s[39m  
  [32;1m✓[39;22m[90m [39m[90mit does not expose the removed generated resolver contract[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestMapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every request attribute[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies sortMap replacing raw sort/order with orderBy[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every uploaded file[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed attribute when missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it lazy-initialises to NullCasterProvider on first read when unset[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed uploaded files by key[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mmap configuration merge[39m[90m → it merges class-level map defaults with constructor-supplied map[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it stores an assigned provider[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed file key when the file is missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed request attributes by name[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies field mapping, defaults and exclude in declaration order[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies cast via the configured CasterProviderInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\PublicApiSignatureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "cache generator"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable invoker call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "lazy request factory make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add parameter resolver"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias set"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add attribute handler"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable resolver resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias has"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder configure from cache"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder compile factories"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory lazy object"[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when a method call is configured[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when constructor params are configured[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it keeps lazy factory objects intact inside factory definitions[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperCollisionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit supports atomic field swaps[39m
  [32;1m✓[39;22m[90m [39m[90mit reads chained mappings from the original input[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects two source fields mapped to one target[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects overwriting an unmapped input field[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it throws ResolutionException when a parameter cannot be resolved[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by name[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → it implements CallableExecutorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by position[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it propagates exceptions thrown inside the callable unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mresolve()[39m[90m → it delegates to the underlying CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it invokes a parameterless callable without asking the parameter resolver for anything[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it forwards resolver failures as InvalidCallableException[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ExternalContainerRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it deduplicates repeated registration of the same instance[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it returns the first owning container in stable registration order[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it does not expose redundant lookup or iteration APIs[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ProxyInjectionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses an explicit concrete class for interface-typed virtual proxies[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an interface proxy when no concrete class can be inferred[39m
  [32;1m✓[39;22m[90m [39m[90mit separates an arbitrary service id from its concrete proxy class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\InvokablePrivateLazyConstructorTest[39m
  [32;1m✓[39;22m[90m [39m[90mit initializes a private no-argument constructor through reflection[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableClosureScopeCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps closure parameter metadata isolated by lexical class scope[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it lets domain exceptions thrown inside the callable propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps PHP engine errors into InvalidCallableException with the original Error as previous[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes the callable and returns its value[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it passes the params list verbatim (no DI, no reordering)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps TypeError from wrongly-typed arguments into InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes an already-valid [object, method] callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes with an empty params list when the callable takes none[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it implements CallableInvokerInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\InvokableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mcan()[39m[90m → it returns true only for registered class ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it handles classes without a constructor (avoids calling __construct on null)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it instantiates the registered class directly[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true only for InvokableDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it rejects a concrete proxy override on a class-level Proxy attribute[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it produces a fresh instance on each resolve call[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition rejects unsupported definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the class, making can() and resolve() succeed[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it returns an instance of the target class (eager for classes without Lazy/Proxy attributes)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\ConfigUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it wraps OutOfBoundsException from the extractor into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it falls back to the SetUp key when Config::$path is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it recognises only Config attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it lets PSR-11 container exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it reads a literal key from the configuration[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves and caches the validation provider only on first use[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionReplacementTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses the latest runtime definition when its resolver kind changes[39m

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

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for built-in types[39m[90m  [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for union types (intentionally unsupported)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for a null type[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for untyped parameters[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns the class/interface name for non-builtin named types[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\CompositeResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it reports can()=false when no resolvers are registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it delegates to the first resolver that claims the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it caches the owner so a later resolve() does not re-scan can() on other resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it passes the context through to the owning resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it returns false from supportsDefinition when no child is definition-aware[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it throws InvalidConfigurationException when no resolver supports the definition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it invalidates the owner cache when a definition is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it forwards setDefinition to the first supporting resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it throws NotFoundException on resolve() when no resolver owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it invalidates the owner cache when a resolver is added[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it negative-caches misses so a subsequent has()+resolve() does not re-scan[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestDataConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a payload value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly opt into the legacy last-source-wins behavior[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly preserve the trusted first source[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts the same value repeated by two request sources[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a query value that conflicts with a request attribute[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Cache\DiCacheGeneratorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it overwrites an existing file with new co[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it writes a PHP file that returns the exact input array[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it preserves the file on unwritable targets (throws before corrupting existing contents)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it produces a file with <?php opener and declare(strict_types=1)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it creates intermediate directories as needed[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it throws InvalidConfigurationException when the config contains unserialisable values[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it implements DiCacheGeneratorInterface[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperSortValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a non-scalar sort alias with a stable mapping exception[39m

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

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\AmbiguousRequestDtoTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects request mapping to more than one possible DTO class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\DevelopmentProductionParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps development reflection and production compiled containers[39m[90m… [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it reflects a mid-chain update after calling set()[39m
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

  [90mTests:[39m    [32;1m324 passed[39;22m[90m (534 assertions)[39m
  [90mDuration:[39m [39m0.38s[39m
  [90mRandom Order Seed:[39m [39m271828[39m

===== seed 161803 =====

  [30;42;1m PASS [39;49;22m[39m Tests\CallableClosureScopeCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps closure parameter metadata isolated by lexical class scope[39m[90m [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\CallableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it rejects a non-string[39m[90m…[39m [90m0.01s[39m  
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

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\ConfigUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it lets PSR-11 container exc[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it falls back to the SetUp key when Config::$path is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it reads a literal key from the configuration[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it wraps OutOfBoundsException from the extractor into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it recognises only Config attribute instances[39m

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

  [30;42;1m PASS [39;49;22m[39m Tests\NullContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it implements PSR-11 ContainerInterface[39m[90m             [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it produces a PSR-11 compatible NotFoundExceptionInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it throws NotFoundException on get() regardless of the id[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "empty string"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "regular id"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "class FQCN"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it includes the requested id in the not-found message[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it keeps lazy factory objects intact inside factory definitions[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when constructor params are configured[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when a method call is configured[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NestedReferenceDefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves reference definitions recursively inside constructor arrays[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps constructor context, injection, setup and no-constructor b[39m[90m…[39m [90m0.02s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\PublicApiSignatureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add attribute handler"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "lazy request factory make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable invoker call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias set"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias has"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "cache generator"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable resolver resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder configure from cache"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add parameter resolver"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder compile factories"[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperCollisionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects overwriting an unmapped input field[39m
  [32;1m✓[39;22m[90m [39m[90mit supports atomic field swaps[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects two source fields mapped to one target[39m
  [32;1m✓[39;22m[90m [39m[90mit reads chained mappings from the original input[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws InvalidConfigurationException for self-referencing alias in the map[39m
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

  [30;42;1m PASS [39;49;22m[39m Tests\CycleGuardTest[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it throws when the same id is entered[39m[90m… [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it exposes the full resolution chain on the cycle exception[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it tolerates leaving an id that was never entered[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it accepts ids that are not currently in-flight[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it allows re-entering an id after it has been left[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes an already-valid [object, method] callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps PHP engine errors into InvalidCallableException with the original Error as previous[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it implements CallableInvokerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes the callable and returns its value[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps TypeError from wrongly-typed arguments into InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it lets domain exceptions thrown inside the callable propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes with an empty params list when the callable takes none[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it passes the params list verbatim (no DI, no reordering)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperPipelineTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it drops the listed keys from the final array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it exclude runs last, removing entries produced by earlier steps (e.g. defaults)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it silently skips optional source keys marked with "?"[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it keeps the value when a mapping keeps the same key name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it ignores exclude entries that are not present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it maps optional keys when they are present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it is a no-op when sortMap is empty[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it skips cast when the key is absent from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort alias is not in the map[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies the configured caster to the value under the matching key[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it returns data unchanged when no mapping rules are set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it throws CasterNotFoundException when the caster is not registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it always strips the raw sort and order keys from output[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it casts against the target key, not the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies casts in the order declared (deterministic for multi-key configs)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it throws InvalidArgumentException when a required source key is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort key is missing from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it fills keys that are absent after mapping[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it applies defaults to the mapped target key (not the source)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it does not overwrite a null value that is already present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it renames source keys to target keys, dropping the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it replaces sort/order keys with orderBy via the map lookup[39m

  [30;42;1m PASS [39;49;22m[39m Tests\InvokableAliasConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a keyed invokable that conflicts with an existing alias[39m[90m  [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects a conflicting fluent invokable alias registration[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a keyed invokable when an existing alias resolves to the same target[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverHardeningTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an existing malformed alias cycle during a later update[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EntryIdUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it fetches the entry from the container using EntryId::$value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it recognises only EntryId instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it propagates NotFoundException when the entry is not registered[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompiledFactoryNamespaceTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects invalid generated factory namespaces before writing source[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperSortValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a non-scalar sort alias with a stable mapping exception[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ExternalContainerRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it returns the first owning container in stable registration order[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it does not expose redundant lookup or iteration APIs[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it deduplicates repeated registration of the same instance[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryArchitectureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit stores compiled autowiring as regular factories and loads shards[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit does not expose the removed generated resolver contract[39m
  [32;1m✓[39;22m[90m [39m[90mit expands only statically knowable concrete dependencies and honours explicit bindings[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it propagates exceptions thrown inside the callable unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mresolve()[39m[90m → it delegates to the underlying CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → it implements CallableExecutorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by name[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it invokes a parameterless callable without asking the parameter resolver for anything[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it forwards resolver failures as InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it throws ResolutionException when a parameter cannot be resolved[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by position[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ProxyFactoryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit reports a missing proxy target as a ReflectionException[39m
  [32;1m✓[39;22m[90m [39m[90mit reports a missing lazy target as a ReflectionException[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ProxyInjectionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit separates an arbitrary service id from its concrete proxy class[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an interface proxy when no concrete class can be inferred[39m
  [32;1m✓[39;22m[90m [39m[90mit uses an explicit concrete class for interface-typed virtual proxies[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\CompositeResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it delegates to the first resolver that claims the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it forwards setDefinition to the first supporting resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it caches the owner so a later resolve() does not re-scan can() on other resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it invalidates the owner cache when a definition is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it passes the context through to the owning resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it reports can()=false when no resolvers are registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it throws NotFoundException on resolve() when no resolver owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it returns false from supportsDefinition when no child is definition-aware[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it invalidates the owner cache when a resolver is added[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it throws InvalidConfigurationException when no resolver supports the definition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it negative-caches misses so a subsequent has()+resolve() does not re-scan[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderRetryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit retries validation provider lookup after a transient failure[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Cache\DiCacheGeneratorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it writes a PHP file that returns the exac[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it throws InvalidConfigurationException when the config contains unserialisable values[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it implements DiCacheGeneratorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it creates intermediate directories as needed[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it produces a file with <?php opener and declare(strict_types=1)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it overwrites an existing file with new contents[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it preserves the file on unwritable targets (throws before corrupting existing contents)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\DevelopmentProductionParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps development reflection and production compiled containers[39m[90m… [39m [90m0.02s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactoryResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes a closure factory with a container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the third lazy factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it unwraps FactoryDefinition and invokes the callable inside[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mcan()[39m[90m → it reports true only for registered ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves ReferenceDefinition values in ClassDefinition constructor params via the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the factory and makes can() return true[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves [string, method] by fetching the object from the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition throws InvalidConfigurationException for unsupported types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true for FactoryDefinition and ClassDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it wraps foreign Throwables from the factory into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves a string-form factory reference through the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the second factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it exposes config to closure factories through the container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is false for unrelated definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it delegates to LazyServiceFactoryInterface::lazy when the factory implements it[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it builds an instance from a ClassDefinition with constructor params[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes methodCalls on the constructed instance in registration order[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestResolverFactoryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit creates the request resolver without resolving validation servic[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\AmbiguousRequestDtoTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects request mapping to more than one possible DTO class[39m

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

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerInvalidTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a non-callable value before invoking the PHP engine[39m[90m      [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\InvokableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the class, making can() and resolve() succeed[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it handles classes without a constructor (avoids calling __construct on null)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true only for InvokableDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it produces a fresh instance on each resolve call[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition rejects unsupported definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it returns an instance of the target class (eager for classes without Lazy/Proxy attributes)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it instantiates the registered class directly[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it rejects a concrete proxy override on a class-level Proxy attribute[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mcan()[39m[90m → it returns true only for registered class ids[39m

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

  [30;42;1m PASS [39;49;22m[39m Tests\RequestMapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed request attributes by name[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed uploaded files by key[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every request attribute[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every uploaded file[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it lazy-initialises to NullCasterProvider on first read when unset[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed attribute when missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it stores an assigned provider[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies sortMap replacing raw sort/order with orderBy[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it omits a listed file key when the file is missing[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies field mapping, defaults and exclude in declaration order[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies cast via the configured CasterProviderInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mmap configuration merge[39m[90m → it merges class-level map defaults with constructor-supplied map[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerBuilderTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects legacy, unknown and malformed dependency configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it does not expose removed legacy API[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it canonicalizes definitions registered through aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects unreachable factories and canonical bindings to protected services[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it materializes custom pipeline extensions before build returns[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it breaks mutual external-container has cycles without hiding get failures[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it normalizes duplicate invokable classes from configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs core pipeline services atomically an[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it builds one runtime container and resolves fresh objects with explicit context[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects multiple binding mechanisms for the same canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it shares the built container identity with bootstrap values and factories[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses one proxy collaborator behind reflection and the public container facade[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs default attribute handlers before materializing custom parameter resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it keeps local base entries ahead of external containers deterministically[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it revalidates a trusted cache after a conflicting runtime binding is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it omits empty and default dependency sections from normalized cache data[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses singular validation for every bulk registration API[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit does not conflate different closure parameter signatures[39m[90m         [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit reuses parameter targets across fresh closures from the same source signature[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompositeResolverConstructionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit normalizes named variadic arguments without changing their call order[39m
  [32;1m✓[39;22m[90m [39m[90mit preserves resolver order supplied through the constructor[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects duplicate resolver identities supplied through the constructor[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EnvUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it rejects anything that is not an Env instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it reads the variable via the explicit name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when environment is unavailable and no default is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it recognises Env attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the attribute default when the variable is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when variable is missing and no default is declared[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it derives the env name from the SetUp key when Env::$name is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the default when Config/Environment is unavailable[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestParameterTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns the registered request instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it returns a new array with the request set under the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns true when the KEY entry is a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false when the KEY entry is not a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it overwrites an existing request at the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false for an empty params array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → it KEY is the ServerRequestInterface FQN so provided-params carry the contract identity[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns null when the request is absent or invalid[39m

  [30;42;1m PASS [39;49;22m[39m Tests\EntryCacheTest[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it does not expose the removed duplicate getter API[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it accepts initial base entries without changing null semantics[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it reads base values through the single tryGet API including null[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it invalidates requested aliases and every sibling of the canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it removes base entries explicitly[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for untyped parameters[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for a null type[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for built-in types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for union types (intentionally unsupported)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns the class/interface name for non-builtin named types[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestDataConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly preserve the trusted first source[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts the same value repeated by two request sources[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a query value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a payload value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly opt into the legacy last-source-wins behavior[39m

  [30;42;1m PASS [39;49;22m[39m Tests\InvokablePrivateLazyConstructorTest[39m
  [32;1m✓[39;22m[90m [39m[90mit initializes a private no-argument constructor through reflection[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ConfigProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit registers the lazy request resolver factory[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionReplacementTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses the latest runtime definition when its resolver kind changes[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\UntypedRequestMappingTest[39m
  [32;1m✓[39;22m[90m [39m[90mit maps an untyped request parameter to an array without invoking the DTO factory[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactorySpecificationValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects malformed factory values during container assembly[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a concrete object factory method that does not exist[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an incomplete compiled definition registered at runtime[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a deferred service method factory specification[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves and caches the validation provider only on first use[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsMatchesTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts an integer for a float declaration like PHP does[39m

  [90mTests:[39m    [32;1m324 passed[39;22m[90m (534 assertions)[39m
  [90mDuration:[39m [39m0.40s[39m
  [90mRandom Order Seed:[39m [39m161803[39m

```
