<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Fixture\typedParam;

function containerHolding(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw NotFoundException::forService($id);
            }
            return $this->entries[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

function containerThatThrows(string $id, Throwable $error): ContainerInterface
{
    return new class ($id, $error) implements ContainerInterface {
        public function __construct(private string $id, private Throwable $error) {}

        public function get(string $id): mixed
        {
            if ($id !== $this->id) {
                throw NotFoundException::forService($id);
            }
            throw $this->error;
        }

        public function has(string $id): bool
        {
            return $id === $this->id;
        }
    };
}

describe('Parameter\\AutowireByTypeResolver', function () {
    it('returns null for untyped parameters', function () {
        $resolver = new AutowireByTypeResolver(containerHolding([]));

        expect($resolver->resolveParameter(typedParam('untyped', 0)))->toBeNull();
    });

    it('returns null for built-in typed parameters', function () {
        $resolver = new AutowireByTypeResolver(containerHolding([]));

        expect($resolver->resolveParameter(typedParam('primitives', 0)))->toBeNull();
    });

    it('returns null when the container has no matching entry (defers to next resolver)', function () {
        $resolver = new AutowireByTypeResolver(containerHolding([]));

        expect($resolver->resolveParameter(typedParam('byType', 0)))->toBeNull();
    });

    it('resolves a typed parameter via the container entry keyed by the class name', function () {
        $logger = new class () implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };
        $resolver = new AutowireByTypeResolver(containerHolding([
            \Psr\Log\LoggerInterface::class => $logger,
        ]));

        expect($resolver->resolveParameter(typedParam('byType', 0)))
            ->toBe([0, $logger]);
    });

    it('compiles and resolves autowire payload from the declared target type', function () {
        $logger = new class () implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };
        $resolver = new AutowireByTypeResolver(containerHolding([
            \Psr\Log\LoggerInterface::class => $logger,
        ]));
        $parameter = typedParam('byType', 0);

        $payload = $resolver->compilePayload($parameter);

        expect($payload)->toBe(['type' => \Psr\Log\LoggerInterface::class])
            ->and($resolver->resolveParameterPlan($parameter, $payload))->toBe([0, $logger]);
    });

    it('wraps unexpected container failures in ResolutionException', function () {
        $boom = new RuntimeException('container boom');
        $resolver = new AutowireByTypeResolver(
            containerThatThrows(\Psr\Log\LoggerInterface::class, $boom),
        );

        try {
            $resolver->resolveParameter(typedParam('byType', 0));
        } catch (ResolutionException $e) {
            expect($e->getPrevious())->toBe($boom);
            return;
        }

        self::fail('expected ResolutionException');
    });
});
