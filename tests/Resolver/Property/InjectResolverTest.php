<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Property\InjectResolver;
use Componenta\DI\Tests\Fixture\TypedProperties;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function Componenta\DI\Tests\Fixture\typedProperty;

function injectContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw NotFoundException::forService($id);
            }
            $value = $this->entries[$id];
            if ($value instanceof Throwable) {
                throw $value;
            }
            return $value;
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

describe('Property\\InjectResolver', function () {
    it('returns null for properties without #[Inject]', function () {
        $resolver = new InjectResolver(injectContainer([]));

        expect($resolver->resolveProperty(typedProperty(TypedProperties::class, 'plain')))
            ->toBeNull();
    });

    it('resolves an #[Inject] class-typed property by looking up the type in the container', function () {
        $logger = new class () implements LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };
        $resolver = new InjectResolver(injectContainer([LoggerInterface::class => $logger]));
        $property = typedProperty(TypedProperties::class, 'logger');

        expect($resolver->resolveProperty($property))->toBe([$property, $logger]);
    });

    it('throws ResolutionException when #[Inject] targets a non-class type', function () {
        $resolver = new InjectResolver(injectContainer([]));

        expect(fn () => $resolver->resolveProperty(typedProperty(TypedProperties::class, 'badInject')))
            ->toThrow(ResolutionException::class);
    });

    it('lets ContainerExceptionInterface exceptions from the container propagate unchanged', function () {
        $original = NotFoundException::forService(LoggerInterface::class);
        $resolver = new InjectResolver(injectContainer([LoggerInterface::class => $original]));

        try {
            $resolver->resolveProperty(typedProperty(TypedProperties::class, 'logger'));
        } catch (Throwable $e) {
            expect($e)->toBe($original);
            return;
        }

        self::fail('expected the container exception to propagate');
    });

    it('wraps foreign Throwables from the container into ResolutionException', function () {
        $boom = new RuntimeException('boom');
        $resolver = new InjectResolver(injectContainer([LoggerInterface::class => $boom]));

        try {
            $resolver->resolveProperty(typedProperty(TypedProperties::class, 'logger'));
        } catch (ResolutionException $e) {
            expect($e->getPrevious())->toBe($boom);
            return;
        }

        self::fail('expected ResolutionException');
    });
});
