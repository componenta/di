<?php

declare(strict_types=1);

use Componenta\DI\Definition\DefinitionInterface;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\DefinitionAwareResolverInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;

function entryResolver(array $handledIds, array $values = []): EntryResolverInterface
{
    return new class ($handledIds, $values) implements EntryResolverInterface {
        public int $canCalls = 0;
        public int $resolveCalls = 0;

        public function __construct(private array $ids, private array $values) {}

        public function can(string $id): bool
        {
            $this->canCalls++;
            return in_array($id, $this->ids, true);
        }

        public function resolve(string $id, array $context = []): mixed
        {
            $this->resolveCalls++;
            return $this->values[$id] ?? new stdClass();
        }
    };
}

function definitionAwareResolver(array $supported = [FactoryDefinition::class]): EntryResolverInterface&DefinitionAwareResolverInterface
{
    return new class ($supported) implements EntryResolverInterface, DefinitionAwareResolverInterface {
        public array $definitions = [];

        public function __construct(private array $supported) {}

        public function can(string $id): bool
        {
            return array_key_exists($id, $this->definitions);
        }

        public function resolve(string $id, array $context = []): mixed
        {
            return 'resolved:' . $id;
        }

        public function setDefinition(string $id, DefinitionInterface $definition): void
        {
            $this->definitions[$id] = $definition;
        }

        public function supportsDefinition(DefinitionInterface $definition): bool
        {
            return in_array($definition::class, $this->supported, true);
        }
    };
}

describe('Resolver\\CompositeResolver', function () {
    it('reports can()=false when no resolvers are registered', function () {
        expect((new CompositeResolver())->can('x'))->toBeFalse();
    });

    it('throws NotFoundException on resolve() when no resolver owns the id', function () {
        $composite = new CompositeResolver();
        $composite->addResolver(entryResolver([]));

        expect(fn () => $composite->resolve('missing'))
            ->toThrow(NotFoundException::class);
    });

    it('delegates to the first resolver that claims the id', function () {
        $composite = new CompositeResolver();
        $first = entryResolver(['a'], ['a' => 'A']);
        $second = entryResolver(['a', 'b'], ['a' => 'A-second', 'b' => 'B']);
        $composite->addResolver($first);
        $composite->addResolver($second);

        expect($composite->resolve('a'))->toBe('A')
            ->and($composite->resolve('b'))->toBe('B');
    });

    it('passes the context through to the owning resolver', function () {
        $composite = new CompositeResolver();
        $capturing = new class () implements EntryResolverInterface {
            public array $context = [];

            public function can(string $id): bool { return $id === 'svc'; }
            public function resolve(string $id, array $context = []): mixed
            {
                $this->context = $context;
                return null;
            }
        };
        $composite->addResolver($capturing);

        $composite->resolve('svc', ['k' => 'v']);

        expect($capturing->context)->toBe(['k' => 'v']);
    });

    it('caches the owner so a later resolve() does not re-scan can() on other resolvers', function () {
        $composite = new CompositeResolver();
        $owner = entryResolver(['svc']);
        $other = entryResolver([]);
        $composite->addResolver($owner);
        $composite->addResolver($other);

        $composite->can('svc');
        $composite->resolve('svc');

        // owner::can was called once during the cache-populating can()
        // check; other::can should never have been consulted because the
        // first resolver already claimed the id.
        expect($owner->canCalls)->toBe(1)
            ->and($other->canCalls)->toBe(0);
    });

    it('negative-caches misses so a subsequent has()+resolve() does not re-scan', function () {
        $composite = new CompositeResolver();
        $a = entryResolver([]);
        $b = entryResolver([]);
        $composite->addResolver($a);
        $composite->addResolver($b);

        $composite->can('missing');
        $composite->can('missing');
        $composite->can('missing');

        expect($a->canCalls)->toBe(1)
            ->and($b->canCalls)->toBe(1);
    });

    it('invalidates the owner cache when a resolver is added', function () {
        $composite = new CompositeResolver();
        $first = entryResolver([]);
        $composite->addResolver($first);
        expect($composite->can('svc'))->toBeFalse();

        $late = entryResolver(['svc']);
        $composite->addResolver($late);

        expect($composite->can('svc'))->toBeTrue();
    });

    describe('DefinitionAwareResolverInterface delegation', function () {
        it('returns false from supportsDefinition when no child is definition-aware', function () {
            $composite = new CompositeResolver();
            $composite->addResolver(entryResolver([]));

            expect($composite->supportsDefinition(new FactoryDefinition(fn () => null)))->toBeFalse();
        });

        it('forwards setDefinition to the first supporting resolver', function () {
            $composite = new CompositeResolver();
            $aware = definitionAwareResolver();
            $plain = entryResolver([]);
            $composite->addResolver($plain);
            $composite->addResolver($aware);

            $definition = new FactoryDefinition(fn () => 'value');
            $composite->setDefinition('svc', $definition);

            expect($aware->definitions)->toBe(['svc' => $definition]);
        });

        it('throws InvalidConfigurationException when no resolver supports the definition', function () {
            $unsupportedDefinition = new class () implements DefinitionInterface {
                public mixed $value { get => null; }
            };
            $composite = new CompositeResolver();
            $composite->addResolver(definitionAwareResolver(supported: [FactoryDefinition::class]));

            expect(fn () => $composite->setDefinition('svc', $unsupportedDefinition))
                ->toThrow(InvalidConfigurationException::class);
        });

        it('invalidates the owner cache when a definition is set', function () {
            $composite = new CompositeResolver();
            $aware = definitionAwareResolver();
            $composite->addResolver($aware);

            expect($composite->can('svc'))->toBeFalse();
            $composite->setDefinition('svc', new FactoryDefinition(fn () => 'v'));

            expect($composite->can('svc'))->toBeTrue();
        });
    });
});
