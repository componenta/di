# Dev verification result

Commit checked: 875aab8ae5111204e2235fb914698beae9b104d2

| Check | Exit code |
|---|---:|
| composer install | 0 |
| PHP lint | 0 |
| CS check | 0 |
| PHPStan | 1 |
| Pest | 0 |

## composer-install

```text
  - Downloading react/child-process (v0.6.7)
  - Downloading fidry/cpu-core-counter (1.3.0)
  - Downloading ergebnis/agent-detector (1.2.0)
  - Downloading composer/xdebug-handler (3.0.5)
  - Downloading composer/semver (3.4.4)
  - Downloading clue/ndjson-react (v1.3.0)
  - Downloading friendsofphp/php-cs-fixer (v3.95.18)
  - Downloading jean85/pretty-package-versions (2.1.1)
  - Downloading myclabs/deep-copy (1.14.0)
  - Downloading staabm/side-effects-detector (1.0.5)
  - Downloading sebastian/version (6.0.0)
  - Downloading sebastian/type (6.0.4)
  - Downloading sebastian/recursion-context (7.0.1)
  - Downloading sebastian/object-reflector (5.0.0)
  - Downloading sebastian/object-enumerator (7.0.0)
  - Downloading sebastian/global-state (8.0.3)
  - Downloading sebastian/exporter (7.0.3)
  - Downloading sebastian/environment (8.1.2)
  - Downloading sebastian/comparator (7.1.8)
  - Downloading sebastian/cli-parser (4.2.1)
  - Downloading phpunit/php-timer (8.0.0)
  - Downloading phpunit/php-text-template (5.0.0)
  - Downloading phpunit/php-invoker (6.0.0)
  - Downloading phpunit/php-file-iterator (6.0.1)
  - Downloading theseer/tokenizer (2.0.1)
  - Downloading sebastian/lines-of-code (4.0.1)
  - Downloading sebastian/complexity (5.0.0)
  - Downloading phpunit/php-code-coverage (12.5.7)
  - Downloading phar-io/version (3.2.1)
  - Downloading phar-io/manifest (2.0.4)
  - Downloading phpunit/phpunit (12.5.33)
  - Downloading pestphp/pest-plugin-profanity (v4.2.1)
  - Downloading psr/simple-cache (3.0.0)
  - Downloading pestphp/pest-plugin-mutate (v4.0.1)
  - Downloading webmozart/assert (2.4.1)
  - Downloading phpstan/phpdoc-parser (2.3.3)
  - Downloading phpdocumentor/reflection-common (2.2.0)
  - Downloading doctrine/deprecations (1.1.6)
  - Downloading phpdocumentor/type-resolver (2.0.0)
  - Downloading phpdocumentor/reflection-docblock (6.0.3)
  - Downloading ta-tikoma/phpunit-architecture-test (0.8.7)
  - Downloading pestphp/pest-plugin-arch (v4.0.2)
  - Downloading nunomaduro/termwind (v2.4.0)
  - Downloading nunomaduro/collision (v8.9.5)
  - Downloading brianium/paratest (v7.20.0)
  - Downloading pestphp/pest (v4.7.8)
  - Downloading phpstan/phpstan (2.2.8)
  - Installing pestphp/pest-plugin (v4.0.0): Extracting archive
  - Installing componenta/arrayable (v1.0.0): Extracting archive
  - Installing componenta/array (v1.0.0): Extracting archive
  - Installing psr/clock (1.0.0): Extracting archive
  - Installing componenta/clock (v1.0.0): Extracting archive
  - Installing componenta/caster (v1.0.0): Extracting archive
  - Installing componenta/priority-list (v1.0.0): Extracting archive
  - Installing componenta/reflection (v1.0.1): Extracting archive
  - Installing psr/http-message (2.0): Extracting archive
  - Installing psr/container (2.0.2): Extracting archive
  - Installing symfony/polyfill-php83 (v1.41.0): Extracting archive
  - Installing spiral/pagination (3.17.1): Extracting archive
  - Installing spiral/core (3.17.2): Extracting archive
  - Installing psr/event-dispatcher (1.0.0): Extracting archive
  - Installing spiral/interceptors (3.17.2): Extracting archive
  - Installing spiral/hmvc (3.17.2): Extracting archive
  - Installing spiral/security (3.17.2): Extracting archive
  - Installing psr/log (3.0.2): Extracting archive
  - Installing cycle/database (2.22.1): Extracting archive
  - Installing componenta/mimetype-detector (v1.0.0): Extracting archive
  - Installing nikic/php-parser (v5.8.0): Extracting archive
  - Installing componenta/var-export (v1.0.0): Extracting archive
  - Installing componenta/config (v2.0.0): Extracting archive
  - Installing componenta/validation (v1.0.1): Extracting archive
  - Installing composer/pcre (3.4.0): Extracting archive
  - Installing filp/whoops (2.18.4): Extracting archive
  - Installing symfony/deprecation-contracts (v3.7.1): Extracting archive
  - Installing symfony/service-contracts (v3.7.1): Extracting archive
  - Installing symfony/stopwatch (v8.1.0): Extracting archive
  - Installing symfony/process (v8.1.0): Extracting archive
  - Installing symfony/polyfill-php84 (v1.38.1): Extracting archive
  - Installing symfony/polyfill-php81 (v1.38.1): Extracting archive
  - Installing symfony/polyfill-php80 (v1.37.0): Extracting archive
  - Installing symfony/polyfill-mbstring (v1.38.2): Extracting archive
  - Installing symfony/options-resolver (v8.1.0): Extracting archive
  - Installing symfony/finder (v8.1.1): Extracting archive
  - Installing symfony/polyfill-ctype (v1.37.0): Extracting archive
  - Installing symfony/filesystem (v8.1.2): Extracting archive
  - Installing symfony/event-dispatcher-contracts (v3.7.1): Extracting archive
  - Installing symfony/event-dispatcher (v8.1.2): Extracting archive
  - Installing symfony/polyfill-intl-normalizer (v1.38.0): Extracting archive
  - Installing symfony/polyfill-intl-grapheme (v1.41.0): Extracting archive
  - Installing symfony/string (v8.1.2): Extracting archive
  - Installing symfony/polyfill-php85 (v1.41.0): Extracting archive
  - Installing symfony/console (v8.1.4): Extracting archive
  - Installing sebastian/diff (7.0.0): Extracting archive
  - Installing react/event-loop (v1.6.0): Extracting archive
  - Installing evenement/evenement (v3.0.2): Extracting archive
  - Installing react/stream (v1.4.0): Extracting archive
  - Installing react/promise (v3.3.0): Extracting archive
  - Installing react/cache (v1.2.0): Extracting archive
  - Installing react/dns (v1.14.0): Extracting archive
  - Installing react/socket (v1.17.0): Extracting archive
  - Installing react/child-process (v0.6.7): Extracting archive
  - Installing fidry/cpu-core-counter (1.3.0): Extracting archive
  - Installing ergebnis/agent-detector (1.2.0): Extracting archive
  - Installing composer/xdebug-handler (3.0.5): Extracting archive
  - Installing composer/semver (3.4.4): Extracting archive
  - Installing clue/ndjson-react (v1.3.0): Extracting archive
  - Installing friendsofphp/php-cs-fixer (v3.95.18): Extracting archive
  - Installing jean85/pretty-package-versions (2.1.1): Extracting archive
  - Installing myclabs/deep-copy (1.14.0): Extracting archive
  - Installing staabm/side-effects-detector (1.0.5): Extracting archive
  - Installing sebastian/version (6.0.0): Extracting archive
  - Installing sebastian/type (6.0.4): Extracting archive
  - Installing sebastian/recursion-context (7.0.1): Extracting archive
  - Installing sebastian/object-reflector (5.0.0): Extracting archive
  - Installing sebastian/object-enumerator (7.0.0): Extracting archive
  - Installing sebastian/global-state (8.0.3): Extracting archive
  - Installing sebastian/exporter (7.0.3): Extracting archive
  - Installing sebastian/environment (8.1.2): Extracting archive
  - Installing sebastian/comparator (7.1.8): Extracting archive
  - Installing sebastian/cli-parser (4.2.1): Extracting archive
  - Installing phpunit/php-timer (8.0.0): Extracting archive
  - Installing phpunit/php-text-template (5.0.0): Extracting archive
  - Installing phpunit/php-invoker (6.0.0): Extracting archive
  - Installing phpunit/php-file-iterator (6.0.1): Extracting archive
  - Installing theseer/tokenizer (2.0.1): Extracting archive
  - Installing sebastian/lines-of-code (4.0.1): Extracting archive
  - Installing sebastian/complexity (5.0.0): Extracting archive
  - Installing phpunit/php-code-coverage (12.5.7): Extracting archive
  - Installing phar-io/version (3.2.1): Extracting archive
  - Installing phar-io/manifest (2.0.4): Extracting archive
  - Installing phpunit/phpunit (12.5.33): Extracting archive
  - Installing pestphp/pest-plugin-profanity (v4.2.1): Extracting archive
  - Installing psr/simple-cache (3.0.0): Extracting archive
  - Installing pestphp/pest-plugin-mutate (v4.0.1): Extracting archive
  - Installing webmozart/assert (2.4.1): Extracting archive
  - Installing phpstan/phpdoc-parser (2.3.3): Extracting archive
  - Installing phpdocumentor/reflection-common (2.2.0): Extracting archive
  - Installing doctrine/deprecations (1.1.6): Extracting archive
  - Installing phpdocumentor/type-resolver (2.0.0): Extracting archive
  - Installing phpdocumentor/reflection-docblock (6.0.3): Extracting archive
  - Installing ta-tikoma/phpunit-architecture-test (0.8.7): Extracting archive
  - Installing pestphp/pest-plugin-arch (v4.0.2): Extracting archive
  - Installing nunomaduro/termwind (v2.4.0): Extracting archive
  - Installing nunomaduro/collision (v8.9.5): Extracting archive
  - Installing brianium/paratest (v7.20.0): Extracting archive
  - Installing pestphp/pest (v4.7.8): Extracting archive
  - Installing phpstan/phpstan (2.2.8): Extracting archive
4 package suggestions were added by new dependencies, use `composer suggest` to see details.
Generating optimized autoload files
Class Componenta\DI\Tests\Fixture\SimpleService located in ./tests/Fixture/ContainerTestFixture.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\Fixture\ServiceWithParam located in ./tests/Fixture/ContainerTestFixture.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledFactoryNamespaceTarget located in ./tests/CompiledFactoryNamespaceTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class PayloadWithRouteId located in ./tests/RequestDataConflictTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class QueryWithRouteId located in ./tests/RequestDataConflictTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class MapPayloadWithNullableSharedAttribute located in ./tests/MapAttributesTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderGeneratedEntry located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderInjectedDependency located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderAttributedResolver located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderLazyEntry located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderProxyFactory located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderExternalContainer located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderMagicDelegator located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\BuilderWithProxyFactory located in ./tests/ContainerBuilderTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CreatesScopedCallableForCacheTest located in ./tests/CallableClosureScopeCacheTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class FirstScopedCallableOwner located in ./tests/CallableClosureScopeCacheTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class SecondScopedCallableOwner located in ./tests/CallableClosureScopeCacheTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\CallableCacheDependency located in ./tests/CallableExecutorCacheTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\AlternateCallableCacheDependency located in ./tests/CallableExecutorCacheTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class ProxyInjectionContract located in ./tests/ProxyInjectionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class ProxyInjectionService located in ./tests/ProxyInjectionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class InterfaceProxyConsumer located in ./tests/ProxyInjectionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class ServiceIdProxyConsumer located in ./tests/ProxyInjectionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class AmbiguousInterfaceProxyConsumer located in ./tests/ProxyInjectionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class ReplacementInvokableService located in ./tests/DefinitionReplacementTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class ExistingInvokableAliasTarget located in ./tests/InvokableAliasConflictTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class RequestedInvokableAliasTarget located in ./tests/InvokableAliasConflictTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class FirstRequestDtoType located in ./tests/Resolver/Parameter/Request/AmbiguousRequestDtoTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class SecondRequestDtoType located in ./tests/Resolver/Parameter/Request/AmbiguousRequestDtoTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityDependency located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityInjected located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityEntry located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityDynamic located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityInvokable located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityExplicit located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityExplicitFactory located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class DevelopmentProductionParityDelegator located in ./tests/Architecture/DevelopmentProductionParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledGraphLeafForTest located in ./tests/Architecture/CompiledFactoryArchitectureTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledGraphSetUpForTest located in ./tests/Architecture/CompiledFactoryArchitectureTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledGraphRootForTest located in ./tests/Architecture/CompiledFactoryArchitectureTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledFactoryLeafForTest located in ./tests/Architecture/CompiledFactoryArchitectureTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledFactoryRootForTest located in ./tests/Architecture/CompiledFactoryArchitectureTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledParityDependencyForTest located in ./tests/Architecture/CompiledFactoryParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledParityEntryForTest located in ./tests/Architecture/CompiledFactoryParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class CompiledParityNoConstructorForTest located in ./tests/Architecture/CompiledFactoryParityTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\ConstructorEntryResolverForTest located in ./tests/CompositeResolverConstructionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class NestedReferenceDependency located in ./tests/NestedReferenceDefinitionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class NestedReferenceConsumer located in ./tests/NestedReferenceDefinitionTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
Class Componenta\DI\Tests\ExternalContainerForRegistryTest located in ./tests/ExternalContainerRegistryTest.php does not comply with psr-4 autoloading standard (rule: Componenta\DI\Tests\ => ./tests). Skipping.
71 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
```

## php-lint

```text
No syntax errors detected in src/Compile/Factory/FactoryCodeGenerator.php
No syntax errors detected in src/Compile/Factory/CompiledFactoryShardWriter.php
No syntax errors detected in src/Compile/Factory/CompiledFactoryShardCompiler.php
No syntax errors detected in src/Compile/Factory/GeneratedFactory.php
No syntax errors detected in src/Compile/Factory/CompiledFactoryDefinition.php
No syntax errors detected in src/Compile/Attribute/GeneratedAttributeCode.php
No syntax errors detected in src/Compile/Attribute/AttributeCodeGenerationContext.php
No syntax errors detected in src/Compile/Attribute/AttributeCodeGenerator.php
No syntax errors detected in src/Compile/Autowire/AutowireEntry.php
No syntax errors detected in src/Compile/Autowire/AutowireEntryContributorInterface.php
No syntax errors detected in src/Compile/Autowire/AutowireClassGraph.php
No syntax errors detected in src/EntryCache.php
No syntax errors detected in src/LazyObjectFactoryInterface.php
No syntax errors detected in src/ExternalContainerRegistry.php
No syntax errors detected in src/VirtualProxyFactoryInterface.php
No syntax errors detected in src/Definition/InvokableDefinition.php
No syntax errors detected in src/Definition/ReferenceDefinition.php
No syntax errors detected in src/Definition/DefinitionInterface.php
No syntax errors detected in src/Definition/FactoryDefinition.php
No syntax errors detected in src/Definition/Definition.php
No syntax errors detected in src/Definition/ClassDefinition.php
No syntax errors detected in src/FactoryInterface.php
No syntax errors detected in src/Cache/DiCacheGenerator.php
No syntax errors detected in src/Cache/DiCacheGeneratorInterface.php
No syntax errors detected in src/Resolver/CastableResolver.php
No syntax errors detected in src/Resolver/EnvResolver.php
No syntax errors detected in src/Resolver/TypeHints.php
No syntax errors detected in src/Resolver/EnvNameNormalizer.php
No syntax errors detected in src/Resolver/Parameter/AutowireByTypeResolver.php
No syntax errors detected in src/Resolver/Parameter/ParameterResolverInterface.php
No syntax errors detected in src/Resolver/Parameter/Request/RequestResolverFactory.php
No syntax errors detected in src/Resolver/Parameter/Request/LazyCasterProvider.php
No syntax errors detected in src/Resolver/Parameter/Request/RequestMapperPipeline.php
No syntax errors detected in src/Resolver/Parameter/Request/RequestDataExtractorInterface.php
No syntax errors detected in src/Resolver/Parameter/Request/LazyFactory.php
No syntax errors detected in src/Resolver/Parameter/Request/MapperInterface.php
No syntax errors detected in src/Resolver/Parameter/Request/RequestResolver.php
No syntax errors detected in src/Resolver/Parameter/Request/RequestParameter.php
No syntax errors detected in src/Resolver/Parameter/Request/RequestDataConflictPolicy.php
No syntax errors detected in src/Resolver/Parameter/Request/ExtractorInterface.php
No syntax errors detected in src/Resolver/Parameter/Request/CastableInterface.php
No syntax errors detected in src/Resolver/Parameter/Request/LazyValidationProvider.php
No syntax errors detected in src/Resolver/Parameter/ParameterResolutionResult.php
No syntax errors detected in src/Resolver/Parameter/ParameterResolutionContext.php
No syntax errors detected in src/Resolver/Parameter/DefaultValueResolver.php
No syntax errors detected in src/Resolver/Parameter/NullableResolver.php
No syntax errors detected in src/Resolver/Parameter/ParametersResolver.php
No syntax errors detected in src/Resolver/Parameter/ArrayTypedResolver.php
No syntax errors detected in src/Resolver/Parameter/ArrayResolver.php
No syntax errors detected in src/Resolver/CurrentUserResolver.php
No syntax errors detected in src/Resolver/EntryIdResolver.php
No syntax errors detected in src/Resolver/Target/ParameterTargetFactory.php
No syntax errors detected in src/Resolver/Target/ParameterTarget.php
No syntax errors detected in src/Resolver/Entry/DefinitionAwareResolverInterface.php
No syntax errors detected in src/Resolver/Entry/ReflectionResolver.php
No syntax errors detected in src/Resolver/Entry/CompositeResolver.php
No syntax errors detected in src/Resolver/Entry/FactorySpecificationValidator.php
No syntax errors detected in src/Resolver/Entry/InvokableResolver.php
No syntax errors detected in src/Resolver/Entry/SetUpRunner.php
No syntax errors detected in src/Resolver/Entry/EntryResolverInterface.php
No syntax errors detected in src/Resolver/Entry/SetUp/EnvUnwrapper.php
No syntax errors detected in src/Resolver/Entry/SetUp/SetUpValueUnwrapperInterface.php
No syntax errors detected in src/Resolver/Entry/SetUp/EntryIdUnwrapper.php
No syntax errors detected in src/Resolver/Entry/SetUp/ContainerValueUnwrapper.php
No syntax errors detected in src/Resolver/Entry/SetUp/ConfigUnwrapper.php
No syntax errors detected in src/Resolver/Entry/FactoryResolver.php
No syntax errors detected in src/Resolver/Entry/InstanceCreator.php
No syntax errors detected in src/Resolver/Entry/ObjectCreationContext.php
No syntax errors detected in src/Resolver/Entry/EntryClassEligibility.php
No syntax errors detected in src/Resolver/CurrentUserProvider.php
No syntax errors detected in src/Resolver/MakeAttributeResolver.php
No syntax errors detected in src/Resolver/CurrentUserProviderInterface.php
No syntax errors detected in src/Resolver/Attribute/AttributeExecutionPlan.php
No syntax errors detected in src/Resolver/Attribute/CreationStrategy.php
No syntax errors detected in src/Resolver/Attribute/AttributeHandlerRegistry.php
No syntax errors detected in src/Resolver/Attribute/AttributePhase.php
No syntax errors detected in src/Resolver/Attribute/CompilableAttributeHandlerInterface.php
No syntax errors detected in src/Resolver/Attribute/AttributeHandlerInterface.php
No syntax errors detected in src/Resolver/Attribute/Handler/ProxyHandler.php
No syntax errors detected in src/Resolver/Attribute/Handler/InitHandler.php
No syntax errors detected in src/Resolver/Attribute/Handler/InjectHandler.php
No syntax errors detected in src/Resolver/Attribute/Handler/LazyHandler.php
No syntax errors detected in src/Resolver/Attribute/Handler/NoConstructorHandler.php
No syntax errors detected in src/Resolver/Attribute/AttributeProcessor.php
No syntax errors detected in src/Resolver/Attribute/AttributeInvocation.php
No syntax errors detected in src/Resolver/ConfigValueExtractor.php
No syntax errors detected in src/Resolver/ConfigAttributeResolver.php
No syntax errors detected in src/Exception/InvalidConfigurationException.php
No syntax errors detected in src/Exception/ResolutionException.php
No syntax errors detected in src/Exception/CircularDependencyException.php
No syntax errors detected in src/Exception/ExceptionInterface.php
No syntax errors detected in src/Exception/CallableExceptionInterface.php
No syntax errors detected in src/Exception/NotFoundException.php
No syntax errors detected in src/Exception/InvalidCallableException.php
No syntax errors detected in src/Exception/RequestDataConflictException.php
No syntax errors detected in src/Exception/DelegatorException.php
No syntax errors detected in src/ProtectedServiceIds.php
No syntax errors detected in src/Container.php
No syntax errors detected in src/Attribute/Env.php
No syntax errors detected in src/Attribute/PayloadParam.php
No syntax errors detected in src/Attribute/Autowire.php
No syntax errors detected in src/Attribute/Config.php
No syntax errors detected in src/Attribute/Cast.php
No syntax errors detected in src/Attribute/MapServerParams.php
No syntax errors detected in src/Attribute/CurrentUser.php
No syntax errors detected in src/Attribute/MapHeaders.php
No syntax errors detected in src/Attribute/Proxy.php
No syntax errors detected in src/Attribute/Init.php
No syntax errors detected in src/Attribute/NoConstructor.php
No syntax errors detected in src/Attribute/RequestMapper.php
No syntax errors detected in src/Attribute/Lazy.php
No syntax errors detected in src/Attribute/MapRequestAttributes.php
No syntax errors detected in src/Attribute/QueryParam.php
No syntax errors detected in src/Attribute/MapCookies.php
No syntax errors detected in src/Attribute/MapUploadedFiles.php
No syntax errors detected in src/Attribute/MapQueryString.php
No syntax errors detected in src/Attribute/MapRequestPayload.php
No syntax errors detected in src/Attribute/RequestAttribute.php
No syntax errors detected in src/Attribute/EntryId.php
No syntax errors detected in src/Attribute/ExtractsRequestData.php
No syntax errors detected in src/Attribute/Inject.php
No syntax errors detected in src/Attribute/UploadedFile.php
No syntax errors detected in src/Attribute/ServerParam.php
No syntax errors detected in src/Attribute/SetUp.php
No syntax errors detected in src/Attribute/Make.php
No syntax errors detected in src/Attribute/Cookie.php
No syntax errors detected in src/Attribute/Header.php
No syntax errors detected in src/CycleGuard.php
No syntax errors detected in src/AliasResolverInterface.php
No syntax errors detected in src/ConfigProvider.php
No syntax errors detected in src/CallableInvokerInterface.php
No syntax errors detected in src/CallableInvoker.php
No syntax errors detected in tests/DefinitionTest.php
No syntax errors detected in tests/EntryCacheTest.php
No syntax errors detected in tests/NullContainerTest.php
No syntax errors detected in tests/AliasResolverHardeningTest.php
No syntax errors detected in tests/ConfigProviderTest.php
No syntax errors detected in tests/Fixture/NonInvokableService.php
No syntax errors detected in tests/Fixture/FakeUploadedFile.php
No syntax errors detected in tests/Fixture/ServiceWithoutConstructor.php
No syntax errors detected in tests/Fixture/functions.php
No syntax errors detected in tests/Fixture/ConfigurableQueryMapper.php
No syntax errors detected in tests/Fixture/ServiceWithParam.php
No syntax errors detected in tests/Fixture/InvokableService.php
No syntax errors detected in tests/Fixture/ContainerTestFixture.php
No syntax errors detected in tests/Fixture/reflection_helpers.php
No syntax errors detected in tests/Fixture/container_helpers.php
No syntax errors detected in tests/Fixture/ServiceWithMethods.php
No syntax errors detected in tests/Fixture/SimpleService.php
No syntax errors detected in tests/Fixture/ClassDefaultMapMapper.php
No syntax errors detected in tests/Fixture/TypedParameters.php
No syntax errors detected in tests/Fixture/FakeServerRequest.php
No syntax errors detected in tests/Fixture/FakeUri.php
No syntax errors detected in tests/RequestMapperTest.php
No syntax errors detected in tests/CallableExecutorTest.php
No syntax errors detected in tests/CompiledFactoryNamespaceTest.php
No syntax errors detected in tests/CallableInvokerTest.php
No syntax errors detected in tests/RequestDataConflictTest.php
No syntax errors detected in tests/MapAttributesTest.php
No syntax errors detected in tests/DelegatorRegistryTest.php
No syntax errors detected in tests/ContainerTest.php
No syntax errors detected in tests/ContainerBuilderTest.php
No syntax errors detected in tests/CallableClosureScopeCacheTest.php
No syntax errors detected in tests/AliasResolverTest.php
No syntax errors detected in tests/CallableExecutorCacheTest.php
No syntax errors detected in tests/ProxyInjectionTest.php
No syntax errors detected in tests/Pest.php
No syntax errors detected in tests/Cache/DiCacheGeneratorTest.php
No syntax errors detected in tests/DefinitionReplacementTest.php
No syntax errors detected in tests/PublicApiSignatureTest.php
No syntax errors detected in tests/InvokableAliasConflictTest.php
No syntax errors detected in tests/Resolver/InvokableResolverTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/RequestParameterTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/RequestMapperCollisionTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/RequestResolverFactoryTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/RequestMapperPipelineTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/LazyValidationProviderTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/AmbiguousRequestDtoTest.php
No syntax errors detected in tests/Resolver/Parameter/Request/LazyValidationProviderRetryTest.php
No syntax errors detected in tests/Resolver/FactorySpecificationValidationTest.php
No syntax errors detected in tests/Resolver/TypeHintsTest.php
No syntax errors detected in tests/Resolver/Entry/SetUp/EntryIdUnwrapperTest.php
No syntax errors detected in tests/Resolver/Entry/SetUp/ConfigUnwrapperTest.php
No syntax errors detected in tests/Resolver/Entry/SetUp/EnvUnwrapperTest.php
No syntax errors detected in tests/Resolver/CompositeResolverTest.php
No syntax errors detected in tests/Resolver/TypeHintsMatchesTest.php
No syntax errors detected in tests/Resolver/FactoryResolverTest.php
No syntax errors detected in tests/Architecture/DevelopmentProductionParityTest.php
No syntax errors detected in tests/Architecture/CompiledFactoryArchitectureTest.php
No syntax errors detected in tests/Architecture/CompiledFactoryParityTest.php
No syntax errors detected in tests/CallableResolverTest.php
No syntax errors detected in tests/CompositeResolverConstructionTest.php
No syntax errors detected in tests/NestedReferenceDefinitionTest.php
No syntax errors detected in tests/ExternalContainerRegistryTest.php
No syntax errors detected in tests/CycleGuardTest.php
No syntax errors detected in verification/run.php
No syntax errors detected in verification/pest-bootstrap.php
No syntax errors detected in benchmarks/GeneratedVsReflectionBench.php
No syntax errors detected in benchmarks/RuntimeBench.php
No syntax errors detected in benchmarks/BuildPhasesBench.php
```

## cs-check

```text
PHP CS Fixer 3.95.18 Adalbertus by Fabien Potencier, Dariusz Ruminski and contributors.
PHP runtime: 8.4.24
Loaded config default.

 Do you want to create the config file? [yes]:
  [0] yes
  [1] no
 > PHP CS Fixer 3.95.18 Adalbertus by Fabien Potencier, Dariusz Ruminski and contributors.
PHP runtime: 8.4.24

 [WARNING] This command is experimental                                         

 ! [NOTE] While we start, we must tell you that we put our diligence to NOT     
 !        change the meaning of your codebase.                                  
 !                                                                              
 !        Yet, some of the rules are explicitly _risky_ to apply. A rule is     
 !        _risky_ if it could change code behaviour, e.g. transforming `==` into
 !        `===` or removal of trailing whitespaces within multiline strings.    
 !                                                                              
 !        Such rules are improving your codebase even further, yet you shall    
 !        always review changes proposed by _risky_ rules carefully.            

 Do you want to enable _risky_ rules? [no]:
  [0] yes
  [1] no
 > 
 ! [NOTE] We recommend usage of `@auto` rulesets. They take insights from your  
 !        existing `composer.json` to configure project the best:               

 * `@autoPHPMigration` - Migration rules to improve code towards the minimum ``PHP`` supported by your project (taken from ``composer.json`` file).
 * `@PER-CS` - Rules that follow `PER Coding Style <https://www.php-fig.org/per/coding-style/>`_, Set is an alias for the latest revision of ``PER-CS`` rules - use it if you always want to be in sync with newest ``PER-CS`` standard.

 Do you want to use `@auto` ruleset? [yes]:
  [0] yes
  [1] no
 > 
 Do you want to use any of other recommended ruleset? (multi-choice) [none]:
  [@PhpCsFixer] Rules recommended by ``PHP CS Fixer`` team, highly opinionated. Extends ``@PER-CS`` and ``@Symfony``.
  [@Symfony   ] Rules that follow the official `Symfony Coding Standards <https://symfony.com/doc/current/contributing/code/standards.html>`_. Extends ``@PER-CS``.
  [none       ] none
 > 
 [OK] Configuration file created successfully as `.php-cs-fixer.dist.php`.      

Config file created, re-run the command to put it in action.
```

## phpstan

```text
 ------ ------------------------------------------------------------------ 
  Line   Resolver/MakeAttributeResolver.php                                
 ------ ------------------------------------------------------------------ 
  143    Using nullsafe property access "?->entry" on left side of ?? is   
         unnecessary. Use -> instead.                                      
         🪪  nullsafe.neverNull                                            
  147    Using nullsafe property access "?->params" on left side of ?? is  
         unnecessary. Use -> instead.                                      
         🪪  nullsafe.neverNull                                            
 ------ ------------------------------------------------------------------ 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Parameter/AutowireByTypeResolver.php                          
 ------ ----------------------------------------------------------------------- 
  29     Method                                                                 
         Componenta\DI\Resolver\Parameter\AutowireByTypeResolver::resolveParam  
         eter() should return array{int, mixed}|null but returns array|null.    
         🪪  return.type                                                        
  30     Parameter #1 $typeName of method                                       
         Componenta\DI\Resolver\Parameter\AutowireByTypeResolver::resolveType(  
         ) expects class-string|null, string|null given.                        
         🪪  argument.type                                                      
  37     Method                                                                 
         Componenta\DI\Resolver\Parameter\AutowireByTypeResolver::resolveType(  
         ) return type has no value type specified in iterable type array.      
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
 ------ ----------------------------------------------------------------------- 

 ------ --------------------------------------------------------------------- 
  Line   Resolver/Parameter/Request/LazyFactory.php                           
 ------ --------------------------------------------------------------------- 
  26     Template type T of method                                            
         Componenta\DI\Resolver\Parameter\Request\LazyFactory::make() is not  
         referenced in a parameter.                                           
         🪪  method.templateTypeNotInParameter                                
  28     Method Componenta\DI\Resolver\Parameter\Request\LazyFactory::make()  
         should return T of object but returns object.                        
         🪪  return.type                                                      
         💡  Type object is not always the same as T. It breaks the contract  
         for some argument types, typically subtypes.                         
 ------ --------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Parameter/Request/MapperInterface.php                         
 ------ ----------------------------------------------------------------------- 
  9      Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\MapperInterface::transform()  
         has parameter $data with no value type specified in iterable type      
         array.                                                                 
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  9      Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\MapperInterface::transform()  
         return type has no value type specified in iterable type array.        
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Parameter/Request/RequestDataExtractorInterface.php           
 ------ ----------------------------------------------------------------------- 
  11     Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterfac  
         e::extract() return type has no value type specified in iterable type  
         array.                                                                 
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Parameter/Request/RequestMapperPipeline.php                   
 ------ ----------------------------------------------------------------------- 
  57     Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline::run()  
         has parameter $sortMap with no value type specified in iterable type   
         array.                                                                 
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  57     Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline::run()  
         return type has no value type specified in iterable type array.        
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  86     Possibly invalid array key type mixed.                                 
         🪪  offsetAccess.invalidOffset                                         
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Parameter/Request/RequestParameter.php                        
 ------ ----------------------------------------------------------------------- 
  39     Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestParameter::has() has   
         parameter $providedParameters with no value type specified in          
         iterable type array.                                                   
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  53     Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestParameter::get()       
         should return Psr\Http\Message\ServerRequestInterface|null but         
         returns mixed.                                                         
         🪪  return.type                                                        
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Parameter/Request/RequestResolver.php                         
 ------ ----------------------------------------------------------------------- 
  76     Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestResolver::resolvePara  
         meter() should return array{int, mixed}|null but returns array|null.   
         🪪  return.type                                                        
  152    Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestResolver::resolveByTy  
         pe() return type has no value type specified in iterable type array.   
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  197    Static property                                                        
         Componenta\DI\Resolver\Parameter\Request\RequestResolver::$inheritanc  
         eCache (array<class-string, bool>) does not accept array<string, bool  
         >.                                                                     
         🪪  assign.propertyType                                                
  212    Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestResolver::processMapp  
         ing() return type has no value type specified in iterable type array.  
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  236    Parameter #1 $type of static method                                    
         Componenta\Reflection\ReflectionType::getTypeNames() expects           
         ReflectionType, ReflectionType|null given.                             
         🪪  argument.type                                                      
  254    Method                                                                 
         Componenta\DI\Resolver\Parameter\Request\RequestResolver::validateDat  
         a() has parameter $data with no value type specified in iterable type  
         array.                                                                 
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
 ------ ----------------------------------------------------------------------- 

 ------ ---------------------------------------------------------------------- 
  Line   Resolver/Target/ParameterTarget.php                                   
 ------ ---------------------------------------------------------------------- 
  31     Property                                                              
         Componenta\DI\Resolver\Target\ParameterTarget::$declaringClass with   
         generic class ReflectionClass does not specify its types: T           
         🪪  missingType.generics                                              
  112    Method                                                                
         Componenta\DI\Resolver\Target\ParameterTarget::firstAttribute()       
         should return (T of object)|null but returns object|null.             
         🪪  return.type                                                       
         💡  Type #1 from the union: Type object is not always the same as T.  
         It breaks the contract for some argument types, typically subtypes.   
 ------ ---------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/TypeHints.php                                                 
 ------ ----------------------------------------------------------------------- 
  25     Method Componenta\DI\Resolver\TypeHints::classOf() has parameter       
         $declaringClass with generic class ReflectionClass but does not        
         specify its types: T                                                   
         🪪  missingType.generics                                               
  42     Method Componenta\DI\Resolver\TypeHints::classNames() has parameter    
         $declaringClass with generic class ReflectionClass but does not        
         specify its types: T                                                   
         🪪  missingType.generics                                               
  70     Method Componenta\DI\Resolver\TypeHints::matches() has parameter       
         $declaringClass with generic class ReflectionClass but does not        
         specify its types: T                                                   
         🪪  missingType.generics                                               
  133    Method Componenta\DI\Resolver\TypeHints::resolveClassName() has        
         parameter $declaringClass with generic class ReflectionClass but does  
         not specify its types: T                                               
         🪪  missingType.generics                                               
  137    Method Componenta\DI\Resolver\TypeHints::resolveClassName() should     
         return class-string|null but returns string|null.                      
         🪪  return.type                                                        
 ------ ----------------------------------------------------------------------- 

 [ERROR] Found 183 errors                                                       

Script phpstan analyse src --level=max handling the phpstan event returned with error code 1
```

## tests

```text
  [33;1m![39;22m[90m [39m[90mit rejects invalid generated factory namespaces before writing sour[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperPipelineTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it applies defaults to the mapped target key (not the source)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it silently skips optional source keys marked with "?"[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it keeps the value when a mapping keeps the same key name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it fills keys that are absent after mapping[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it throws InvalidArgumentException when a required source key is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it replaces sort/order keys with orderBy via the map lookup[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it always strips the raw sort and order keys from output[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies casts in the order declared (deterministic for multi-key configs)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it renames source keys to target keys, dropping the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it exclude runs last, removing entries produced by earlier steps (e.g. defaults)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it returns data unchanged when no mapping rules are set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it is a no-op when sortMap is empty[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it casts against the target key, not the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it does not overwrite a null value that is already present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies the configured caster to the value under the matching key[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort alias is not in the map[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it drops the listed keys from the final array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it skips cast when the key is absent from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort key is missing from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it maps optional keys when they are present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it ignores exclude entries that are not present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it throws CasterNotFoundException when the caster is not registered[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestDataConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts the same value repeated by two request sources[39m[90m           [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects a payload value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly opt into the legacy last-source-wins behavior[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly preserve the trusted first source[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a query value that conflicts with a request attribute[39m

  [30;42;1m PASS [39;49;22m[39m Tests\InvokableAliasConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a keyed invokable that conflicts with an existing alias[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a conflicting fluent invokable alias registration[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a keyed invokable when an existing alias resolves to the same target[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ExternalContainerRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it does not expose redundant lookup or iteration APIs[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it returns the first owning container in stable registration order[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it deduplicates repeated registration of the same instance[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Cache\DiCacheGeneratorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it implements DiCacheGeneratorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it overwrites an existing file with new contents[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it creates intermediate directories as needed[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it throws InvalidConfigurationException when the config contains unserialisable values[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it writes a PHP file that returns the exact input array[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it produces a file with <?php opener and declare(strict_types=1)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it preserves the file on unwritable targets (throws before corrupting existing contents)[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompositeResolverConstructionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit normalizes named variadic arguments without changing their call order[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects duplicate resolver identities supplied through the constructor[39m
  [32;1m✓[39;22m[90m [39m[90mit preserves resolver order supplied through the constructor[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ProxyInjectionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses an explicit concrete class for interface-typed virtual proxies[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an interface proxy when no concrete class can be inferred[39m
  [32;1m✓[39;22m[90m [39m[90mit separates an arbitrary service id from its concrete proxy class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NullContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "regular id"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "class FQCN"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "empty string"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it implements PSR-11 ContainerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it produces a PSR-11 compatible NotFoundExceptionInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it throws NotFoundException on get() regardless of the id[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it includes the requested id in the not-found message[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CycleGuardTest[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it accepts ids that are not currently in-flight[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it exposes the full resolution chain on the cycle exception[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it tolerates leaving an id that was never entered[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it throws when the same id is entered twice without leaving[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it allows re-entering an id after it has been left[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestResolverFactoryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit creates the request resolver without resolving validation services[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryArchitectureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit stores compiled autowiring as regular factories and loads shards[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit does not expose the removed generated resolver contract[39m
  [32;1m✓[39;22m[90m [39m[90mit expands only statically knowable concrete dependencies and honours explicit bindings[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit does not conflate different closure parameter signatures[39m
  [32;1m✓[39;22m[90m [39m[90mit reuses parameter targets across fresh closures from the same source signature[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\InvokableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the class, making can() and resolve() succeed[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it returns an instance of the target class (eager for classes without Lazy/Proxy attributes)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition rejects unsupported definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it instantiates the registered class directly[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true only for InvokableDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mcan()[39m[90m → it returns true only for registered class ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it handles classes without a constructor (avoids calling __construct on null)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it produces a fresh instance on each resolve call[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\CompositeResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it throws InvalidConfigurationException when no resolver supports the definition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it throws NotFoundException on resolve() when no resolver owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it invalidates the owner cache when a definition is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it delegates to the first resolver that claims the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it passes the context through to the owning resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it invalidates the owner cache when a resolver is added[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it caches the owner so a later resolve() does not re-scan can() on other resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it forwards setDefinition to the first supporting resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it reports can()=false when no resolvers are registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it negative-caches misses so a subsequent has()+resolve() does not re-scan[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it returns false from supportsDefinition when no child is definition-aware[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderRetryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit retries validation provider lookup after a transient failure[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DelegatorRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it resolves non-callable registrations via the CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it returns the entry unchanged when no delegators are registered[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it passes entry and container to the delegator and returns its result[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a delegator's foreign exception in DelegatorException with entry id and previous[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it applies delegators in registration order, threading the return value[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it caches resolved callables across repeated apply() calls[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after invalidate() drops the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it keeps raw registrations on invalidate(); apply still runs the delegator[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after register() invalidates the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses an already-callable non-Closure delegator directly[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses a Closure delegator directly without going through the callable resolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a resolution-time foreign exception in DelegatorException[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps PHP engine errors into InvalidCallableException with the original Error as previous[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it passes the params list verbatim (no DI, no reordering)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes an already-valid [object, method] callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps TypeError from wrongly-typed arguments into InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it implements CallableInvokerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes the callable and returns its value[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it lets domain exceptions thrown inside the callable propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes with an empty params list when the callable takes none[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\ConfigUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it lets PSR-11 container exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it wraps OutOfBoundsException from the extractor into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it recognises only Config attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it reads a literal key from the configuration[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it falls back to the SetUp key when Config::$path is null[39m

  [30;42;1m PASS [39;49;22m[39m Tests\EntryCacheTest[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it removes base entries explicitly[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it accepts initial base entries without changing null semantics[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it reads base values through the single tryGet API including null[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it does not expose the removed duplicate getter API[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it invalidates requested aliases and every sibling of the canonical id[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it does not apply delegators registered on the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcycle detection[39m[90m → it throws CircularDependencyException when factories form a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for an unsupported definition type[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it invalidates cached results for the alias name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it returns a fresh instance on each call (no caching)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it applies registered delegators in order to the resolved entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it registers an alias that resolves to the target entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the same instance on repeat get() calls (cached)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it invalidates cached resolution when a delegator is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it propagates NotFoundException for a string the resolver chain cannot handle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it throws NotFoundException for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it keeps registered class definition state stable after later fluent changes[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns false from has() for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it passes user-supplied params to the constructor by name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it resolves aliases transparently[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcall()[39m[90m → it invokes the callable with DI-resolved parameters[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mself-registration[39m[90m → it exposes itself under every interface it implements[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the value registered via set()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mexternal containers[39m[90m → it delegates get() to an external container that owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it invalidates a cached entry when set() runs for the same id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it resolves aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it accepts a DefinitionInterface and resolves it on get()[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps constructor context, injection, setup and no-constructor b[39m[90m…[39m [90m0.02s[39m  
  [31m────────────────────────────────────────────────────────────────────────────[39m  
  [30;43;1m WARNINGS [39;49;22m [1mTests\CompiledFactoryNamespaceTest[22m [90m>[39m it rejects invalid generat…   
[39;1m  rmdir(/tmp/componenta-invalid-factory-namespace-0b4ed7d567): No such file or directory[39;22m

  at [32mtests/CompiledFactoryNamespaceTest.php[39m:[32m30[39m
     26▕     } finally {
     27▕         foreach (glob($directory . '/*') ?: [] as $file) {
     28▕             @unlink($file);
     29▕         }
  ➜  30▕         @rmdir($directory);
     31▕     }
     32▕ });
     33▕


  [90mTests:[39m    [33;1m1 warning[39;22m[90m,[39m[39m [39m[32;1m313 passed[39;22m[90m (520 assertions)[39m
  [90mDuration:[39m [39m0.30s[39m
  [90mRandom Order Seed:[39m [39m1786452745[39m

```

