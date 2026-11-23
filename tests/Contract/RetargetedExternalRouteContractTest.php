<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RetargetedExternalRoute;

use Closure;
use Componenta\Config\DependencyDefinitions;
use Componenta\DI\Container;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use DomainException;
use LogicException;
use Psr\Container\ContainerInterface;

final class Lookup
{
    public Container $di;
    /** @var list<string> */
    public array $events = [];
}

final readonly class Resolver implements EntryResolverInterface
{
    /** @param Closure(string):bool $can */
    public function __construct(private Closure $can) {}

    public function can(string $id): bool
    {
        return ($this->can)($id);
    }

    public function resolve(string $id, EntryParameters $params): mixed
    {
        return 'local backup';
    }
}

final class Product {}

final readonly class Provider implements ContainerInterface
{
    /** @param Closure():bool $has */
    public function __construct(private string $id, private Closure $has, private mixed $value) {}

    public function has(string $id): bool
    {
        return $id === $this->id && ($this->has)();
    }

    public function get(string $id): mixed
    {
        return $id === $this->id ? $this->value : throw NotFoundException::forService($id);
    }
}

test('a provider published during retargeting is checked before the new local fallback', function (bool $read, bool $owns): void {
    $lookup = new Lookup();
    $earlier = new Provider('preferred', static function () use ($lookup): bool {
        $lookup->events[] = 'earlier provider';
        return false;
    }, null);
    $remote = new Provider('preferred', static function () use ($lookup, $owns): bool {
        $lookup->events[] = 'new provider';
        return $owns;
    }, 'remote product');
    $resolver = new Resolver(static function (string $id) use ($lookup, $remote, $owns): bool {
        $lookup->events[] = $id;
        if ($id === 'old local') {
            $lookup->di->addContainer($remote);
            $lookup->di->alias('preferred', 'new local');
            return false;
        }
        if ($owns) {
            throw new LogicException('The local backup cannot answer this request.');
        }
        return true;
    });
    $lookup->di = new Container($resolver, new ContainerBuilder()->build(), aliases: ['preferred' => 'old local']);
    $lookup->di->addContainer($earlier);

    $result = $read ? $lookup->di->get('preferred') : $lookup->di->has('preferred');
    expect($result)->toBe($read ? ($owns ? 'remote product' : 'local backup') : true)
        ->and($lookup->events)->toBe($owns
            ? ['earlier provider', 'old local', 'new provider']
            : ['earlier provider', 'old local', 'new provider', 'new local']);
})->with(['get' => true, 'has' => false])
    ->with(['provider owns' => true, 'provider misses' => false]);

test('optional autowiring can select a valid provider registered during alias retargeting', function (): void {
    $di = new ContainerBuilder()->build();
    $legacy = __NAMESPACE__ . '\\Legacy_' . bin2hex(random_bytes(5));
    $backup = __NAMESPACE__ . '\\Backup_' . bin2hex(random_bytes(5));
    $result = new Product();
    $events = [];
    $di->alias(Product::class, $legacy);
    $provider = new Provider(Product::class, static function () use (&$events): bool {
        $events[] = 'provider';
        return true;
    }, $result);
    $loader = static function (string $id) use ($di, $legacy, $backup, $provider, &$events): void {
        if ($id === $legacy) {
            $events[] = 'old local';
            $di->alias(Product::class, $backup);
            $di->addContainer($provider);
        } elseif ($id === $backup) {
            $events[] = 'new local';
            throw new LogicException('The local backup cannot answer this request.');
        }
    };
    spl_autoload_register($loader);

    try {
        $actual = $di->call(static fn(?Product $product = null): ?Product => $product);
        expect($actual)->toBe($result)
            ->and($events)->toBe(['old local', 'provider', 'provider']);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('an already published value on the new alias target proves availability', function (): void {
    $lookup = new Lookup();
    $provider = new Provider('preferred', static fn(): never => throw new LogicException('A cached value is sufficient.'), null);
    $resolver = new Resolver(static function (string $id) use ($lookup, $provider): bool {
        $lookup->events[] = $id;
        $lookup->di->addContainer($provider);
        $lookup->di->alias('preferred', 'new local');
        return false;
    });
    $lookup->di = new Container(
        $resolver,
        new ContainerBuilder()->build(),
        aliases: ['preferred' => 'old local'],
        services: ['new local' => null],
    );

    expect($lookup->di->has('preferred'))->toBeTrue()
        ->and($lookup->events)->toBe(['old local']);
});

test('a pending provider ownership failure is reported before an unproven replacement fallback', function (): void {
    $lookup = new Lookup();
    $cause = new DomainException('Ownership source failed.');
    $provider = new Provider('preferred', static function () use ($lookup, $cause): never {
        $lookup->events[] = 'new provider';
        throw $cause;
    }, null);
    $resolver = new Resolver(static function (string $id) use ($lookup, $provider): bool {
        $lookup->events[] = $id;
        if ($id === 'old local') {
            $lookup->di->addContainer($provider);
            $lookup->di->alias('preferred', 'new local');
            return false;
        }
        return true;
    });
    $lookup->di = new Container($resolver, new ContainerBuilder()->build(), aliases: ['preferred' => 'old local']);

    try {
        $lookup->di->has('preferred');
        throw new LogicException('Expected the ownership failure.');
    } catch (ResolutionException $failure) {
        expect($failure->serviceId)->toBe('preferred')
            ->and($failure->getPrevious())->toBe($cause)
            ->and($lookup->events)->toBe(['old local', 'new provider']);
    }
});

test('an existing provider becomes eligible before a new local probe after leaving core protection', function (bool $read): void {
    $lookup = new Lookup();
    $unavailableCore = DependencyDefinitions::class;
    $provider = new Provider('preferred', static function () use ($lookup): bool {
        $lookup->events[] = 'provider';
        return true;
    }, 'remote product');
    $resolver = new Resolver(static function (string $id) use ($lookup, $unavailableCore): bool {
        $lookup->events[] = $id;
        if ($id === $unavailableCore) {
            $lookup->di->alias('preferred', 'new local');
            return false;
        }
        throw new LogicException('An eligible provider owns the entry.');
    });
    $lookup->di = new Container($resolver, new ContainerBuilder()->build(), aliases: ['preferred' => $unavailableCore]);
    $lookup->di->addContainer($provider);

    expect($read ? $lookup->di->get('preferred') : $lookup->di->has('preferred'))
        ->toBe($read ? 'remote product' : true)
        ->and($lookup->events)->toBe([$unavailableCore, 'provider']);
})->with(['get' => true, 'has' => false]);

test('a newly protected alias bypasses a pending external provider', function (bool $available): void {
    $lookup = new Lookup();
    $core = $available ? ContainerInterface::class : DependencyDefinitions::class;
    $provider = new Provider('preferred', static fn(): never => throw new LogicException('A protected alias cannot be claimed.'), null);
    $resolver = new Resolver(static function (string $id) use ($lookup, $provider, $core): bool {
        $lookup->events[] = $id;
        if ($id === 'old local') {
            $lookup->di->addContainer($provider);
            $lookup->di->alias('preferred', $core);
        }
        return false;
    });
    $lookup->di = new Container($resolver, new ContainerBuilder()->build(), aliases: ['preferred' => 'old local']);

    expect($lookup->di->has('preferred'))->toBe($available)
        ->and($lookup->events)->toBe($available ? ['old local'] : ['old local', $core]);
})->with(['published core value' => true, 'unavailable core ID' => false]);

test('a provider in a new suffix cannot claim an alias it retargeted to protected core', function (bool $owns): void {
    $lookup = new Lookup();
    $later = new Provider('preferred', static fn(): never => throw new LogicException('Later provider is blocked by protection.'), null);
    $provider = new Provider('preferred', static function () use ($lookup, $owns): bool {
        $lookup->events[] = 'new provider';
        $lookup->di->alias('preferred', ContainerInterface::class);
        return $owns;
    }, 'remote product');
    $resolver = new Resolver(static function (string $id) use ($lookup, $provider, $later): bool {
        $lookup->events[] = $id;
        if ($id === 'old local') {
            $lookup->di->alias('preferred', 'new local');
            $lookup->di->addContainer($provider);
            $lookup->di->addContainer($later);
            return false;
        }
        throw new LogicException('Current route is protected.');
    });
    $lookup->di = new Container($resolver, new ContainerBuilder()->build(), aliases: ['preferred' => 'old local']);

    expect($lookup->di->has('preferred'))->toBeTrue()
        ->and($lookup->events)->toBe(['old local', 'new provider'])
        ->and($lookup->di->get('preferred'))->toBe($lookup->di);
})->with(['owns' => true, 'misses' => false]);
