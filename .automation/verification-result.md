# Dev verification result

Commit checked: 94109a9986c833835a650210ef8fac6dd68e6e01

| Check | Exit code | Gating |
|---|---:|---|
| composer install | 0 | yes |
| composer validate --strict | 0 | yes |
| PHP lint | 0 | yes |
| PHPStan: changed source files | 0 | yes |
| PHPStan: complete src baseline | 1 | no |
| Pest | 0 | yes |

## composer-install

```text
No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.
Loading composer repositories with package information
Updating dependencies
Lock file operations: 100 installs, 0 updates, 0 removals
  - Locking brianium/paratest (v7.20.0)
  - Locking clue/ndjson-react (v1.3.0)
  - Locking componenta/array (v1.0.0)
  - Locking componenta/arrayable (v1.0.0)
  - Locking componenta/caster (v1.0.0)
  - Locking componenta/clock (v1.0.0)
  - Locking componenta/config (v2.0.0)
  - Locking componenta/mimetype-detector (v1.0.0)
  - Locking componenta/priority-list (v1.0.0)
  - Locking componenta/reflection (v1.0.1)
  - Locking componenta/validation (v1.0.1)
  - Locking componenta/var-export (v1.0.0)
  - Locking composer/pcre (3.4.0)
  - Locking composer/semver (3.4.4)
  - Locking composer/xdebug-handler (3.0.5)
  - Locking cycle/database (2.22.1)
  - Locking doctrine/deprecations (1.1.6)
  - Locking ergebnis/agent-detector (1.2.0)
  - Locking evenement/evenement (v3.0.2)
  - Locking fidry/cpu-core-counter (1.3.0)
  - Locking filp/whoops (2.18.4)
  - Locking friendsofphp/php-cs-fixer (v3.95.18)
  - Locking jean85/pretty-package-versions (2.1.1)
  - Locking myclabs/deep-copy (1.14.0)
  - Locking nikic/php-parser (v5.8.0)
  - Locking nunomaduro/collision (v8.9.5)
  - Locking nunomaduro/termwind (v2.4.0)
  - Locking pestphp/pest (v4.7.8)
  - Locking pestphp/pest-plugin (v4.0.0)
  - Locking pestphp/pest-plugin-arch (v4.0.2)
  - Locking pestphp/pest-plugin-mutate (v4.0.1)
  - Locking pestphp/pest-plugin-profanity (v4.2.1)
  - Locking phar-io/manifest (2.0.4)
  - Locking phar-io/version (3.2.1)
  - Locking phpdocumentor/reflection-common (2.2.0)
  - Locking phpdocumentor/reflection-docblock (6.0.3)
  - Locking phpdocumentor/type-resolver (2.0.0)
  - Locking phpstan/phpdoc-parser (2.3.3)
  - Locking phpstan/phpstan (2.2.8)
  - Locking phpunit/php-code-coverage (12.5.7)
  - Locking phpunit/php-file-iterator (6.0.1)
  - Locking phpunit/php-invoker (6.0.0)
  - Locking phpunit/php-text-template (5.0.0)
  - Locking phpunit/php-timer (8.0.0)
  - Locking phpunit/phpunit (12.5.33)
  - Locking psr/clock (1.0.0)
  - Locking psr/container (2.0.2)
  - Locking psr/event-dispatcher (1.0.0)
  - Locking psr/http-message (2.0)
  - Locking psr/log (3.0.2)
  - Locking psr/simple-cache (3.0.0)
  - Locking react/cache (v1.2.0)
  - Locking react/child-process (v0.6.7)
  - Locking react/dns (v1.14.0)
  - Locking react/event-loop (v1.6.0)
  - Locking react/promise (v3.3.0)
  - Locking react/socket (v1.17.0)
  - Locking react/stream (v1.4.0)
  - Locking sebastian/cli-parser (4.2.1)
  - Locking sebastian/comparator (7.1.8)
  - Locking sebastian/complexity (5.0.0)
  - Locking sebastian/diff (7.0.0)
  - Locking sebastian/environment (8.1.2)
  - Locking sebastian/exporter (7.0.3)
  - Locking sebastian/global-state (8.0.3)
  - Locking sebastian/lines-of-code (4.0.1)
  - Locking sebastian/object-enumerator (7.0.0)
  - Locking sebastian/object-reflector (5.0.0)
  - Locking sebastian/recursion-context (7.0.1)
  - Locking sebastian/type (6.0.4)
  - Locking sebastian/version (6.0.0)
  - Locking spiral/core (3.17.2)
  - Locking spiral/hmvc (3.17.2)
  - Locking spiral/interceptors (3.17.2)
  - Locking spiral/pagination (3.17.1)
  - Locking spiral/security (3.17.2)
  - Locking staabm/side-effects-detector (1.0.5)
  - Locking symfony/console (v8.1.4)
  - Locking symfony/deprecation-contracts (v3.7.1)
  - Locking symfony/event-dispatcher (v8.1.2)
  - Locking symfony/event-dispatcher-contracts (v3.7.1)
  - Locking symfony/filesystem (v8.1.2)
  - Locking symfony/finder (v8.1.1)
  - Locking symfony/options-resolver (v8.1.0)
  - Locking symfony/polyfill-ctype (v1.37.0)
  - Locking symfony/polyfill-intl-grapheme (v1.41.0)
  - Locking symfony/polyfill-intl-normalizer (v1.38.0)
  - Locking symfony/polyfill-mbstring (v1.38.2)
  - Locking symfony/polyfill-php80 (v1.37.0)
  - Locking symfony/polyfill-php81 (v1.38.1)
  - Locking symfony/polyfill-php83 (v1.41.0)
  - Locking symfony/polyfill-php84 (v1.38.1)
  - Locking symfony/polyfill-php85 (v1.41.0)
  - Locking symfony/process (v8.1.0)
  - Locking symfony/service-contracts (v3.7.1)
  - Locking symfony/stopwatch (v8.1.0)
  - Locking symfony/string (v8.1.2)
  - Locking ta-tikoma/phpunit-architecture-test (0.8.7)
  - Locking theseer/tokenizer (2.0.1)
  - Locking webmozart/assert (2.4.1)
Writing lock file
Installing dependencies from lock file (including require-dev)
Package operations: 100 installs, 0 updates, 0 removals
  - Downloading pestphp/pest-plugin (v4.0.0)
  - Downloading componenta/arrayable (v1.0.0)
  - Downloading componenta/array (v1.0.0)
  - Downloading psr/clock (1.0.0)
  - Downloading componenta/clock (v1.0.0)
  - Downloading componenta/caster (v1.0.0)
  - Downloading componenta/priority-list (v1.0.0)
  - Downloading componenta/reflection (v1.0.1)
  - Downloading psr/http-message (2.0)
  - Downloading psr/container (2.0.2)
  - Downloading symfony/polyfill-php83 (v1.41.0)
  - Downloading spiral/pagination (3.17.1)
  - Downloading spiral/core (3.17.2)
  - Downloading psr/event-dispatcher (1.0.0)
  - Downloading spiral/interceptors (3.17.2)
  - Downloading spiral/hmvc (3.17.2)
  - Downloading spiral/security (3.17.2)
  - Downloading psr/log (3.0.2)
  - Downloading cycle/database (2.22.1)
  - Downloading componenta/mimetype-detector (v1.0.0)
  - Downloading nikic/php-parser (v5.8.0)
  - Downloading componenta/var-export (v1.0.0)
  - Downloading componenta/config (v2.0.0)
  - Downloading componenta/validation (v1.0.1)
  - Downloading composer/pcre (3.4.0)
  - Downloading filp/whoops (2.18.4)
  - Downloading symfony/deprecation-contracts (v3.7.1)
  - Downloading symfony/service-contracts (v3.7.1)
  - Downloading symfony/stopwatch (v8.1.0)
  - Downloading symfony/process (v8.1.0)
  - Downloading symfony/polyfill-php84 (v1.38.1)
  - Downloading symfony/polyfill-php81 (v1.38.1)
  - Downloading symfony/polyfill-php80 (v1.37.0)
  - Downloading symfony/polyfill-mbstring (v1.38.2)
  - Downloading symfony/options-resolver (v8.1.0)
  - Downloading symfony/finder (v8.1.1)
  - Downloading symfony/polyfill-ctype (v1.37.0)
  - Downloading symfony/filesystem (v8.1.2)
  - Downloading symfony/event-dispatcher-contracts (v3.7.1)
  - Downloading symfony/event-dispatcher (v8.1.2)
  - Downloading symfony/polyfill-intl-normalizer (v1.38.0)
  - Downloading symfony/polyfill-intl-grapheme (v1.41.0)
  - Downloading symfony/string (v8.1.2)
  - Downloading symfony/polyfill-php85 (v1.41.0)
  - Downloading symfony/console (v8.1.4)
  - Downloading sebastian/diff (7.0.0)
  - Downloading react/event-loop (v1.6.0)
  - Downloading evenement/evenement (v3.0.2)
  - Downloading react/stream (v1.4.0)
  - Downloading react/promise (v3.3.0)
  - Downloading react/cache (v1.2.0)
  - Downloading react/dns (v1.14.0)
  - Downloading react/socket (v1.17.0)
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

## composer-validate

```text
./composer.json is valid
```

## php-lint

```text
No syntax errors detected in src/CallableExecutorInterface.php
No syntax errors detected in src/ContainerBuilder.php
No syntax errors detected in src/LazyServiceFactoryInterface.php
No syntax errors detected in src/ConfigKey.php
No syntax errors detected in src/CallableExecutor.php
No syntax errors detected in src/DelegatorRegistry.php
No syntax errors detected in src/NullContainer.php
No syntax errors detected in src/AliasResolver.php
No syntax errors detected in src/CallableResolverInterface.php
No syntax errors detected in src/ProxyFactoryInterface.php
No syntax errors detected in src/ProxyFactory.php
No syntax errors detected in src/CallableResolver.php
No syntax errors detected in src/Compile/Parameter/DefaultParameterResolverCodeGenerators.php
No syntax errors detected in src/Compile/Parameter/ParameterResolverCodeGeneratorRegistry.php
No syntax errors detected in src/Compile/Parameter/ParameterCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/GeneratedResolverCode.php
No syntax errors detected in src/Compile/Parameter/EmptyContextResolution.php
No syntax errors detected in src/Compile/Parameter/PhpValueExporter.php
No syntax errors detected in src/Compile/Parameter/GeneratedResolverCodeType.php
No syntax errors detected in src/Compile/Parameter/Generator/ArrayTypedResolverCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/Generator/NullableResolverCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/Generator/ArrayResolverCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/Generator/DefaultValueResolverCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/Generator/RuntimeParameterResolverCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/Generator/AutowireByTypeResolverCodeGenerator.php
No syntax errors detected in src/Compile/Parameter/GeneratedParameterCode.php
No syntax errors detected in src/Compile/Parameter/ParameterResolverCodeGeneratorInterface.php
No syntax errors detected in src/Compile/Parameter/ParameterCodeGenerationContext.php
No syntax errors detected in src/Compile/Factory/FactoryCode.php
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

## phpstan-changed

```text
No changed source files.
```

## phpstan-full

```text
  30     Method Componenta\DI\Resolver\Attribute\AttributeProcessor::process()  
         has parameter $class with generic class ReflectionClass but does not   
         specify its types: T                                                   
         🪪  missingType.generics                                               
  61     Method                                                                 
         Componenta\DI\Resolver\Attribute\AttributeProcessor::invocations()     
         has parameter $class with generic class ReflectionClass but does not   
         specify its types: T                                                   
         🪪  missingType.generics                                               
  71     Method Componenta\DI\Resolver\Attribute\AttributeProcessor::plan()     
         has parameter $class with generic class ReflectionClass but does not   
         specify its types: T                                                   
         🪪  missingType.generics                                               
  133    Method                                                                 
         Componenta\DI\Resolver\Attribute\AttributeProcessor::properties() has  
         parameter $class with generic class ReflectionClass but does not       
         specify its types: T                                                   
         🪪  missingType.generics                                               
  149    Method Componenta\DI\Resolver\Attribute\AttributeProcessor::methods()  
         has parameter $class with generic class ReflectionClass but does not   
         specify its types: T                                                   
         🪪  missingType.generics                                               
  169    Method Componenta\DI\Resolver\Attribute\AttributeProcessor::collect()  
         has parameter $target with generic class ReflectionClass but does not  
         specify its types: T                                                   
         🪪  missingType.generics                                               
 ------ ----------------------------------------------------------------------- 

 ------ --------------------------------------------------------------------- 
  Line   Resolver/CastableResolver.php                                        
 ------ --------------------------------------------------------------------- 
  148    Dead catch - Componenta\DI\Exception\ResolutionException is already  
         caught above.                                                        
         🪪  catch.alreadyCaught                                              
  189    Dead catch - Componenta\DI\Exception\ResolutionException is already  
         caught above.                                                        
         🪪  catch.alreadyCaught                                              
 ------ --------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/ConfigAttributeResolver.php                                   
 ------ ----------------------------------------------------------------------- 
  110    Parameter #1 $configData of method                                     
         Componenta\DI\Resolver\ConfigValueExtractor::extract() expects array<  
         string, mixed>|ArrayAccess, mixed given.                               
         🪪  argument.type                                                      
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/ConfigValueExtractor.php                                      
 ------ ----------------------------------------------------------------------- 
  47     Method Componenta\DI\Resolver\ConfigValueExtractor::extract() has      
         parameter $configData with generic interface ArrayAccess but does not  
         specify its types: TKey, TValue                                        
         🪪  missingType.generics                                               
  54     Parameter #4 $segments of method                                       
         Componenta\DI\Resolver\ConfigValueExtractor::extractCompiled()         
         expects list<string>, array<string> given.                             
         🪪  argument.type                                                      
         💡  array<int|string, string> might not be a list.                     
  76     Method Componenta\DI\Resolver\ConfigValueExtractor::extractCompiled()  
         has parameter $configData with generic interface ArrayAccess but does  
         not specify its types: TKey, TValue                                    
         🪪  missingType.generics                                               
  134    Method Componenta\DI\Resolver\ConfigValueExtractor::extractNested()    
         has parameter $configData with generic interface ArrayAccess but does  
         not specify its types: TKey, TValue                                    
         🪪  missingType.generics                                               
  134    Method Componenta\DI\Resolver\ConfigValueExtractor::extractNested()    
         has parameter $configData with no value type specified in iterable     
         type array.                                                            
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  161    Method Componenta\DI\Resolver\ConfigValueExtractor::extractLiteral()   
         has parameter $configData with generic interface ArrayAccess but does  
         not specify its types: TKey, TValue                                    
         🪪  missingType.generics                                               
  161    Method Componenta\DI\Resolver\ConfigValueExtractor::extractLiteral()   
         has parameter $configData with no value type specified in iterable     
         type array.                                                            
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  174    Method Componenta\DI\Resolver\ConfigValueExtractor::hasKey() has       
         parameter $configData with generic interface ArrayAccess but does not  
         specify its types: TKey, TValue                                        
         🪪  missingType.generics                                               
  174    Method Componenta\DI\Resolver\ConfigValueExtractor::hasKey() has       
         parameter $configData with no value type specified in iterable type    
         array.                                                                 
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
 ------ ----------------------------------------------------------------------- 

 ------ ---------------------------------------------------------------------- 
  Line   Resolver/Entry/EntryClassEligibility.php                              
 ------ ---------------------------------------------------------------------- 
  20     Method Componenta\DI\Resolver\Entry\EntryClassEligibility::allows()   
         has parameter $class with generic class ReflectionClass but does not  
         specify its types: T                                                  
         🪪  missingType.generics                                              
 ------ ---------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Entry/FactoryResolver.php                                     
 ------ ----------------------------------------------------------------------- 
  51     Method Componenta\DI\Resolver\Entry\FactoryResolver::__construct()     
         has parameter $factories with no value type specified in iterable      
         type array.                                                            
         🪪  missingType.iterableValue                                          
         💡  See:                                                               
         https://phpstan.org/blog/solving-phpstan-no-value-type-specified-in-i  
         terable-type                                                           
  61     Call to function is_string() with string will always evaluate to       
         true.                                                                  
         🪪  function.alreadyNarrowedType                                       
         💡  Because the type is coming from a PHPDoc, you can turn off this    
         check by setting treatPhpDocTypesAsCertain: false in your phpstan.neo  
         n.                                                                     
  103    Trying to invoke mixed but it's not a callable.                        
         🪪  callable.nonCallable                                               
  256    Property Componenta\DI\Resolver\Entry\FactoryResolver::$factories      
         (array<string, array|(callable(Componenta\Config\ContainerValue,       
         array<int|string, mixed>): mixed)|Componenta\DI\Compile\Factory\Compi  
         ledFactoryDefinition|Componenta\DI\Definition\ClassDefinition|Compone  
         nta\DI\Definition\FactoryDefinition|string>) does not accept           
         non-empty-array<string,                                                
         array|(callable(Componenta\Config\ContainerValue, array<int|string, m  
         ixed>): mixed)|Componenta\DI\Definition\DefinitionInterface|string>.   
         🪪  assign.propertyType                                                
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Entry/FactorySpecificationValidator.php                       
 ------ ----------------------------------------------------------------------- 
  26     Unreachable statement - code above always terminates.                  
         🪪  deadCode.unreachable                                               
  33     Strict comparison using !== between class-string and '' will always    
         evaluate to true.                                                      
         🪪  notIdentical.alwaysTrue                                            
         💡  Because the type is coming from a PHPDoc, you can turn off this    
         check by setting treatPhpDocTypesAsCertain: false in your phpstan.neo  
         n.                                                                     
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Entry/InstanceCreator.php                                     
 ------ ----------------------------------------------------------------------- 
  27     Method Componenta\DI\Resolver\Entry\InstanceCreator::create() has      
         parameter $reflector with generic class ReflectionClass but does not   
         specify its types: T                                                   
         🪪  missingType.generics                                               
  45     Method Componenta\DI\Resolver\Entry\InstanceCreator::initialize() has  
         parameter $reflector with generic class ReflectionClass but does not   
         specify its types: T                                                   
         🪪  missingType.generics                                               
  54     Call to an undefined method object::__construct().                     
         🪪  method.notFound                                                    
 ------ ----------------------------------------------------------------------- 

 ------ ---------------------------------------------------------------------- 
  Line   Resolver/Entry/InvokableResolver.php                                  
 ------ ---------------------------------------------------------------------- 
  61     Call to an undefined method object::__construct().                    
         🪪  method.notFound                                                   
  80     Property Componenta\DI\Resolver\Entry\InvokableResolver::$invokables  
         (array<string, class-string>) does not accept array<string, mixed>.   
         🪪  assign.propertyType                                               
 ------ ---------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Entry/ObjectCreationContext.php                               
 ------ ----------------------------------------------------------------------- 
  34     Method                                                                 
         Componenta\DI\Resolver\Entry\ObjectCreationContext::__construct() has  
         parameter $class with generic class ReflectionClass but does not       
         specify its types: T                                                   
         🪪  missingType.generics                                               
 ------ ----------------------------------------------------------------------- 

 ------ ----------------------------------------------------------------------- 
  Line   Resolver/Entry/SetUp/ConfigUnwrapper.php                               
 ------ ----------------------------------------------------------------------- 
  45     Parameter #1 $configData of method                                     
         Componenta\DI\Resolver\ConfigValueExtractor::extract() expects array<  
         string, mixed>|ArrayAccess, mixed given.                               
         🪪  argument.type                                                      
 ------ ----------------------------------------------------------------------- 

 ------ ------------------------------------------------------------------ 
  Line   Resolver/Entry/SetUpRunner.php                                    
 ------ ------------------------------------------------------------------ 
  190    Method Componenta\DI\Resolver\Entry\SetUpRunner::method() has     
         parameter $class with generic class ReflectionClass but does not  
         specify its types: T                                              
         🪪  missingType.generics                                          
 ------ ------------------------------------------------------------------ 

 ------ ------------------------------------------------------------- 
  Line   Resolver/EnvNameNormalizer.php                               
 ------ ------------------------------------------------------------- 
  29     Parameter #1 $string of function strtoupper expects string,  
         string|null given.                                           
         🪪  argument.type                                            
 ------ ------------------------------------------------------------- 

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

 [ERROR] Found 181 errors                                                       

Script phpstan analyse src --level=max handling the phpstan event returned with error code 1
```

## tests

```text
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it extracts listed request attributes by name[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies cast via the configured CasterProviderInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it stores an assigned provider[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mprovider property hook[39m[90m → it lazy-initialises to NullCasterProvider on first read when unset[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mextract() base behaviour (via MapQueryString subclass)[39m[90m → it wildcard * extracts every request attribute[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\RequestMapper[39m[90m → [39m[37mtransform() - full pipeline integration[39m[90m → it applies sortMap replacing raw sort/order with orderBy[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperPipelineTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m →[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies the configured caster to the value under the matching key[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it ignores exclude entries that are not present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it throws InvalidArgumentException when a required source key is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it silently skips optional source keys marked with "?"[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it skips cast when the key is absent from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it replaces sort/order keys with orderBy via the map lookup[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort alias is not in the map[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it returns data unchanged when no mapping rules are set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mexclude step[39m[90m → it drops the listed keys from the final array[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it does not overwrite a null value that is already present[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it exclude runs last, removing entries produced by earlier steps (e.g. defaults)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it throws CasterNotFoundException when the caster is not registered[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it keeps the value when a mapping keeps the same key name[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it is a no-op when sortMap is empty[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it casts against the target key, not the source[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mcast step[39m[90m → it applies casts in the order declared (deterministic for multi-key configs)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it always strips the raw sort and order keys from output[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mstep ordering: mapFields -> cast -> defaults -> sortMap -> exclude[39m[90m → it applies defaults to the mapped target key (not the source)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37msortMap step[39m[90m → it sets orderBy to null when the sort key is missing from data[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mdefaults step[39m[90m → it fills keys that are absent after mapping[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestMapperPipeline[39m[90m → [39m[37mmapFields step[39m[90m → it maps optional keys when they are present[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryArchitectureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit stores compiled autowiring as regular factories and loads shards[39m[90m…[39m [90m0.03s[39m  
  [32;1m✓[39;22m[90m [39m[90mit does not expose the removed generated resolver contract[39m
  [32;1m✓[39;22m[90m [39m[90mit expands only statically knowable concrete dependencies and honours explicit bindings[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EnvUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it derives the env name from the SetUp key when Env::$name is null[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the default when Config/Environment is unavailable[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it rejects anything that is not an Env instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it returns the attribute default when the variable is missing[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37msupports()[39m[90m → it recognises Env attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when environment is unavailable and no default is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it throws ResolutionException when variable is missing and no default is declared[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EnvUnwrapper[39m[90m → [39m[37munwrap()[39m[90m → it reads the variable via the explicit name[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Cache\DiCacheGeneratorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it creates intermediate directories as nee[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it produces a file with <?php opener and declare(strict_types=1)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it writes a PHP file that returns the exact input array[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it overwrites an existing file with new contents[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it implements DiCacheGeneratorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it preserves the file on unwritable targets (throws before corrupting existing contents)[39m
  [32;1m✓[39;22m[90m [39m[37mCache\DiCacheGenerator[39m[90m → it throws InvalidConfigurationException when the config contains unserialisable values[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves and caches the validation provider only on first use[39m[90m    [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by position[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it throws ResolutionException when a parameter cannot be resolved[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it invokes a parameterless callable without asking the parameter resolver for anything[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it forwards resolver failures as InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → it implements CallableExecutorInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it passes provided parameters to the callable by name[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mcall()[39m[90m → it propagates exceptions thrown inside the callable unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableExecutor[39m[90m → [39m[37mresolve()[39m[90m → it delegates to the underlying CallableResolver[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableExecutorCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit reuses parameter targets across fresh closures from the same source signature[39m
  [32;1m✓[39;22m[90m [39m[90mit does not conflate different closure parameter signatures[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it keeps lazy factory objects intact inside factory definitions[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when constructor params are configured[39m
  [32;1m✓[39;22m[90m [39m[37mDefinition[39m[90m → it returns a new class definition when a method call is configured[39m

  [30;42;1m PASS [39;49;22m[39m Tests\InvokableAliasConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a keyed invokable that conflicts with an existing alias[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a keyed invokable when an existing alias resolves to the same target[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a conflicting fluent invokable alias registration[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableClosureScopeCacheTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps closure parameter metadata isolated by lexical class scope[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it returns the resolver instance for fluent chaining[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it accepts a cyclic map when skipValidation is true (deferred detection)[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws InvalidConfigurationException for self-referencing alias in the map[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mcaching[39m[90m → it invalidates the resolution cache when a link is updated[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it walks the alias chain to the terminal target[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it registers the alias so it resolves to the target[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it returns the resolver instance for fluent chaining[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mcaching[39m[90m → it invalidates the resolution cache on unset()[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it leaves the map untouched when the update is rejected for a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mhas()[39m[90m → it returns true only for registered alias keys, not targets[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → it implements AliasResolverInterface[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for a self-referencing alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mconstructor validation[39m[90m → it throws CircularDependencyException for a cycle across the map[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mset()[39m[90m → it throws CircularDependencyException when the new mapping would close a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it defensively throws on cycle even when validation was skipped at construction[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it is a no-op for an id that is not a registered alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it stops chain resolution at the removed link[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37miteration[39m[90m → it reflects later set() calls[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it reflects a mid-chain update after calling set()[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37munset()[39m[90m → it removes the alias from the registry[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37mresolve()[39m[90m → it returns the id unchanged when it is not a registered alias[39m
  [32;1m✓[39;22m[90m [39m[37mAliasResolver[39m[90m → [39m[37miteration[39m[90m → it yields the alias->target pairs[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DelegatorRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a delegator's foreign except[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it resolves non-callable registrations via the CallableResolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after register() invalidates the cache[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it applies delegators in registration order, threading the return value[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses a Closure delegator directly without going through the callable resolver[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it caches resolved callables across repeated apply() calls[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it wraps a resolution-time foreign exception in DelegatorException[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it passes entry and container to the delegator and returns its result[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it uses an already-callable non-Closure delegator directly[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it keeps raw registrations on invalidate(); apply still runs the delegator[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it returns the entry unchanged when no delegators are registered[39m
  [32;1m✓[39;22m[90m [39m[37mDelegatorRegistry[39m[90m → [39m[37mapply()[39m[90m → it re-resolves after invalidate() drops the cache[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NestedReferenceDefinitionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit resolves reference definitions recursively inside constructor ar[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\ConfigUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it lets PSR-11 container exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it recognises only Config attribute instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it wraps OutOfBoundsException from the extractor into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it reads a literal key from the configuration[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\ConfigUnwrapper[39m[90m → it falls back to the SetUp key when Config::$path is null[39m

  [30;42;1m PASS [39;49;22m[39m Tests\EntryCacheTest[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it reads base values through the single tryGet API including null[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it accepts initial base entries without changing null semantics[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it removes base entries explicitly[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it does not expose the removed duplicate getter API[39m
  [32;1m✓[39;22m[90m [39m[37mEntryCache[39m[90m → it invalidates requested aliases and every sibling of the canonical id[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\InvokableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it handles classes without a constructor (avoids calling __construct on null)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true only for InvokableDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the class, making can() and resolve() succeed[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mcan()[39m[90m → it returns true only for registered class ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() with a real PHP84 proxy factory[39m[90m → it returns an instance of the target class (eager for classes without Lazy/Proxy attributes)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it instantiates the registered class directly[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition rejects unsupported definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\InvokableResolver[39m[90m → [39m[37mresolve() without a proxy factory (eager by default)[39m[90m → it produces a fresh instance on each resolve call[39m

  [30;42;1m PASS [39;49;22m[39m Tests\PublicApiSignatureTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder configure from cache"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias set"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container make"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder compile factories"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add parameter resolver"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "cache generator"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable executor resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable resolver resolve"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "proxy factory lazy object"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "callable invoker call"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "container virtual proxy"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "alias has"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "builder add attribute handler"[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps public named-argument contracts aligned with implementations with dataset "lazy request factory make"[39m

  [30;42;1m PASS [39;49;22m[39m Tests\MapAttributesTest[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapQueryString[39m[90m → it extracts the query string parameters[39m[90m… [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapQueryString[39m[90m → it returns an empty array when there are no query parameters[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestAttributes[39m[90m → it extracts the entire request attribute bag[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it extracts a parsed body array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapCookies[39m[90m → it returns an empty array when no cookies are present[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapServerParams[39m[90m → it extracts the server params into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapServerParams[39m[90m → it returns an empty array when no server params are set[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it treats a null parsed body as an empty array (no merge error)[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapHeaders[39m[90m → it joins multi-value headers with ", "[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it flattens a parsed body object via get_object_vars[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapUploadedFiles[39m[90m → it returns an empty array when there are no uploaded files[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapCookies[39m[90m → it extracts the cookie parameters into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestPayload[39m[90m → it preserves selected request attributes with explicit null values[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapUploadedFiles[39m[90m → it extracts the uploaded-files bag into the data array[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapHeaders[39m[90m → it extracts single-value headers as plain strings[39m
  [32;1m✓[39;22m[90m [39m[37mAttribute\MapRequestAttributes[39m[90m → it returns an empty array when no request attributes are set[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns the class/interface name[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for untyped parameters[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for a null type[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for union types (intentionally unsupported)[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\TypeHints::classOf()[39m[90m → it returns null for built-in types[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\AmbiguousRequestDtoTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects request mapping to more than one possible DTO class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ExternalContainerRegistryTest[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it does not expose redundant lookup or iteration APIs[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it deduplicates repeated registration of the same instance[39m
  [32;1m✓[39;22m[90m [39m[37mExternalContainerRegistry[39m[90m → it returns the first owning container in stable registration order[39m

  [30;42;1m PASS [39;49;22m[39m Tests\NullContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it implements PSR-11 ContainerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it throws NotFoundException on get() regardless of the id[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "class FQCN"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "empty string"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it reports every id as absent with dataset "regular id"[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it produces a PSR-11 compatible NotFoundExceptionInterface[39m
  [32;1m✓[39;22m[90m [39m[37mNullContainer[39m[90m → it includes the requested id in the not-found message[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CycleGuardTest[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it throws when the same id is entered twice without leaving[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it tolerates leaving an id that was never entered[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it allows re-entering an id after it has been left[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it exposes the full resolution chain on the cycle exception[39m
  [32;1m✓[39;22m[90m [39m[37mCycleGuard[39m[90m → [39m[37menter / leave[39m[90m → it accepts ids that are not currently in-flight[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableInvokerTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it passes the params list verbatim (no DI, no reordering)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it lets domain exceptions thrown inside the callable propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it implements CallableInvokerInterface[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps TypeError from wrongly-typed arguments into InvalidCallableException[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes with an empty params list when the callable takes none[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes the callable and returns its value[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it wraps PHP engine errors into InvalidCallableException with the original Error as previous[39m
  [32;1m✓[39;22m[90m [39m[37mCallableInvoker[39m[90m → it invokes an already-valid [object, method] callable[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerBuilderTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it breaks mutual external-container has cycles without hiding get failures[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it omits empty and default dependency sections from normalized cache data[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it normalizes duplicate invokable classes from configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it does not expose removed legacy API[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses one proxy collaborator behind reflection and the public container facade[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it keeps local base entries ahead of external containers deterministically[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects legacy, unknown and malformed dependency configuration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects multiple binding mechanisms for the same canonical id[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it canonicalizes definitions registered through aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it materializes custom pipeline extensions before build returns[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs core pipeline services atomically and forbids rebinding or decoration[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it builds one runtime container and resolves fresh objects with explicit context[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it rejects unreachable factories and canonical bindings to protected services[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it revalidates a trusted cache after a conflicting runtime binding is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it installs default attribute handlers before materializing custom parameter resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it uses singular validation for every bulk registration API[39m
  [32;1m✓[39;22m[90m [39m[37mContainerBuilder[39m[90m → it shares the built container identity with bootstrap values and factories[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\CompiledFactoryParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps constructor context, injection, setup and no-constructor b[39m[90m…[39m [90m0.02s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactoryResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mcan()[39m[90m → it reports true only for registered ids[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the second factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition throws InvalidConfigurationException for unsupported types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is true for FactoryDefinition and ClassDefinition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it wraps foreign Throwables from the factory into ResolutionException[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes a closure factory with a container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it passes resolution context as the third lazy factory argument[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it lets ContainerExceptionInterface exceptions propagate unchanged[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it unwraps FactoryDefinition and invokes the callable inside[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it setDefinition registers the factory and makes can() return true[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves [string, method] by fetching the object from the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it builds an instance from a ClassDefinition with constructor params[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it invokes methodCalls on the constructed instance in registration order[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mdefinition support[39m[90m → it supportsDefinition is false for unrelated definition types[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it exposes config to closure factories through the container value[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves a string-form factory reference through the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it resolves ReferenceDefinition values in ClassDefinition constructor params via the container[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\FactoryResolver[39m[90m → [39m[37mresolve()[39m[90m → it delegates to LazyServiceFactoryInterface::lazy when the factory implements it[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ProxyInjectionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an interface proxy when no concrete class can be inferre[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit uses an explicit concrete class for interface-typed virtual proxies[39m
  [32;1m✓[39;22m[90m [39m[90mit separates an arbitrary service id from its concrete proxy class[39m

  [30;42;1m PASS [39;49;22m[39m Tests\DefinitionReplacementTest[39m
  [32;1m✓[39;22m[90m [39m[90mit uses the latest runtime definition when its resolver kind changes[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Architecture\DevelopmentProductionParityTest[39m
  [32;1m✓[39;22m[90m [39m[90mit keeps development reflection and production compiled containers[39m[90m… [39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestParameterTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false when the KEY entry is not a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns the registered request instance[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mget()[39m[90m → it returns null when the request is absent or invalid[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns true when the KEY entry is a ServerRequestInterface[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → it KEY is the ServerRequestInterface FQN so provided-params carry the contract identity[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it overwrites an existing request at the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mwith()[39m[90m → it returns a new array with the request set under the KEY[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Parameter\Request\RequestParameter[39m[90m → [39m[37mhas()[39m[90m → it returns false for an empty params array[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\TypeHintsMatchesTest[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts an integer for a float declaration like PHP does[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\FactorySpecificationValidationTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects malformed factory values during container assembly[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts a deferred service method factory specification[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\LazyValidationProviderRetryTest[39m
  [32;1m✓[39;22m[90m [39m[90mit retries validation provider lookup after a transient failure[39m

  [30;42;1m PASS [39;49;22m[39m Tests\AliasResolverHardeningTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects an existing malformed alias cycle during a later update[39m

  [30;42;1m PASS [39;49;22m[39m Tests\RequestDataConflictTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a query value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects a payload value that conflicts with a request attribute[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly opt into the legacy last-source-wins behavior[39m
  [32;1m✓[39;22m[90m [39m[90mit can explicitly preserve the trusted first source[39m
  [32;1m✓[39;22m[90m [39m[90mit accepts the same value repeated by two request sources[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ContainerTest[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it invalidates cached results for the alias name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37malias()[39m[90m → it registers an alias that resolves to the target entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the same instance on repeat get() calls (cached)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns false from has() for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it returns the value registered via set()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it invalidates cached resolution when a delegator is added[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it accepts a DefinitionInterface and resolves it on get()[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it throws InvalidConfigurationException for an unsupported definition type[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it invalidates a cached entry when set() runs for the same id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mexternal containers[39m[90m → it delegates get() to an external container that owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it returns a fresh instance on each call (no caching)[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mself-registration[39m[90m → it exposes itself under every interface it implements[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mdelegators[39m[90m → it applies registered delegators in order to the resolved entry[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it passes user-supplied params to the constructor by name[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mset()[39m[90m → it keeps registered class definition state stable after later fluent changes[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it does not apply delegators registered on the id[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it propagates NotFoundException for a string the resolver chain cannot handle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it throws NotFoundException for unknown ids[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mmake()[39m[90m → it resolves aliases[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mget() / has()[39m[90m → it resolves aliases transparently[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcycle detection[39m[90m → it throws CircularDependencyException when factories form a cycle[39m
  [32;1m✓[39;22m[90m [39m[37mContainer[39m[90m → [39m[37mcall()[39m[90m → it invokes the callable with DI-resolved parameters[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Entry\SetUp\EntryIdUnwrapperTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it propagates NotFoundExcep[39m[90m…[39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it recognises only EntryId instances[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\Entry\SetUp\EntryIdUnwrapper[39m[90m → it fetches the entry from the container using EntryId::$value[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CallableResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it resolves [class-string, staticMethod] without the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it wraps a [object, method] array callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it rejects arrays that are not exactly length 2[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it throws for an unknown string that is neither service nor function nor class[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it throws when the container entry is not callable[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when [class-string, method] targets an instance method but the container has no entry[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it returns a callable service from the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it reports an existing class as a missing service (needs container wiring)[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws for arrays whose first element is neither object nor string[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws when the class in Class::method does not exist[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mplain string resolution[39m[90m → it falls back to a plain global function when no service is registered[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it resolves a static method without consulting the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it resolves an instance method by fetching the class instance from the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it resolves [class-string, instanceMethod] via container lookup[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it returns a Closure as-is[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when the class part does not exist[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37m[class, method] arrays[39m[90m → it throws when the method does not exist on the object[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws when an instance method is requested but the class is not in the container[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37munsupported input types[39m[90m → it throws InvalidCallableException for integers[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mClass::method strings[39m[90m → it throws with forMethod variant when the method is missing[39m
  [32;1m✓[39;22m[90m [39m[37mCallableResolver[39m[90m → [39m[37mclosures and callable objects[39m[90m → it wraps an invokable object in a first-class callable that forwards to __invoke[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompiledFactoryNamespaceTest[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects invalid generated factory namespaces before writing sour[39m[90m…[39m [90m0.01s[39m  

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\CompositeResolverTest[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it invalidates the owner cache when a definition is set[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it caches the owner so a later resolve() does not re-scan can() on other resolvers[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it forwards setDefinition to the first supporting resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it negative-caches misses so a subsequent has()+resolve() does not re-scan[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it returns false from supportsDefinition when no child is definition-aware[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it throws NotFoundException on resolve() when no resolver owns the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → [39m[37mDefinitionAwareResolverInterface delegation[39m[90m → it throws InvalidConfigurationException when no resolver supports the definition[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it invalidates the owner cache when a resolver is added[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it delegates to the first resolver that claims the id[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it passes the context through to the owning resolver[39m
  [32;1m✓[39;22m[90m [39m[37mResolver\CompositeResolver[39m[90m → it reports can()=false when no resolvers are registered[39m

  [30;42;1m PASS [39;49;22m[39m Tests\Resolver\Parameter\Request\RequestMapperCollisionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit reads chained mappings from the original input[39m[90m                   [39m [90m0.01s[39m  
  [32;1m✓[39;22m[90m [39m[90mit rejects overwriting an unmapped input field[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects two source fields mapped to one target[39m
  [32;1m✓[39;22m[90m [39m[90mit supports atomic field swaps[39m

  [30;42;1m PASS [39;49;22m[39m Tests\CompositeResolverConstructionTest[39m
  [32;1m✓[39;22m[90m [39m[90mit normalizes named variadic arguments without changing their call order[39m
  [32;1m✓[39;22m[90m [39m[90mit rejects duplicate resolver identities supplied through the constructor[39m
  [32;1m✓[39;22m[90m [39m[90mit preserves resolver order supplied through the constructor[39m

  [30;42;1m PASS [39;49;22m[39m Tests\ConfigProviderTest[39m
  [32;1m✓[39;22m[90m [39m[90mit registers the lazy request resolver factory[39m

  [90mTests:[39m    [32;1m314 passed[39;22m[90m (520 assertions)[39m
  [90mDuration:[39m [39m0.42s[39m
  [90mRandom Order Seed:[39m [39m1786453148[39m

```

