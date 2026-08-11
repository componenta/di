# Dev verification result

Commit checked: 2669a85a3feb8094ce6ef96b0be8aa8b0a21e864

| Check | Exit code |
|---|---:|
| composer install | 1 |
| PHP lint | 125 |
| CS check | 125 |
| PHPStan | 125 |
| Pest | 125 |

## composer-install

```text
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
::error ::pestphp/pest-plugin contains a Composer plugin which is blocked by your allow-plugins config. You may add it to the list if you consider it safe.%0AYou can run "composer config --no-plugins allow-plugins.pestphp/pest-plugin [true|false]" to enable it (true) or disable it explicitly and suppress this exception (false)%0ASee https://getcomposer.org/allow-plugins

In PluginManager.php line 821:
                                                                               
  pestphp/pest-plugin contains a Composer plugin which is blocked by your all  
  ow-plugins config. You may add it to the list if you consider it safe.       
  You can run "composer config --no-plugins allow-plugins.pestphp/pest-plugin  
   [true|false]" to enable it (true) or disable it explicitly and suppress th  
  is exception (false)                                                         
  See https://getcomposer.org/allow-plugins                                    
                                                                               

install [--prefer-source] [--prefer-dist] [--prefer-install PREFER-INSTALL] [--dry-run] [--download-only] [--dev] [--no-suggest] [--no-dev] [--no-security-blocking] [--no-blocking] [--no-autoloader] [--no-progress] [--no-install] [--audit] [--audit-format AUDIT-FORMAT] [-v|vv|vvv|--verbose] [-o|--optimize-autoloader] [-a|--classmap-authoritative] [--strict-psr-autoloader] [--apcu-autoloader] [--apcu-autoloader-prefix APCU-AUTOLOADER-PREFIX] [--ignore-platform-req IGNORE-PLATFORM-REQ] [--ignore-platform-reqs] [--] [<packages>...]

```

## php-lint

```text
```

## cs-check

```text
```

## phpstan

```text
```

## tests

```text
```

