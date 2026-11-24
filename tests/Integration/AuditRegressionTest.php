<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Integration\AuditRegression;

use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuditMappedFactoryDto
{
    public function __construct(public string $value) {}
}

final readonly class AuditMappedFactoryEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public AuditMappedFactoryDto $dto,
    ) {}
}

final readonly class AuditLazyProduct
{
    public function __construct(public string $label) {}
}

final class AuditLazyFactory implements LazyServiceFactoryInterface
{
    public ?ContainerInterface $seenContainer = null;
    /** @var array<string|int,mixed> */
    public array $seenContext = [];
    public int $calls = 0;
    public int $initializations = 0;

    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        ++$this->calls;
        $this->seenContainer = $container;
        $this->seenContext = $context;
        $label = $context['label'] ?? 'default';
        if (!is_string($label)) {
            throw new \LogicException('Expected a string label.');
        }

        return $proxyFactory->makeProxy(AuditLazyProduct::class, function () use ($label): AuditLazyProduct {
            ++$this->initializations;
            return new AuditLazyProduct($label);
        });
    }

    public function __invoke(ContainerInterface $container): object
    {
        throw new \RuntimeException('Lazy factory must use lazy().');
    }
}

test('mapped request provenance stays internal when a DTO is created by a user factory', function (): void {
    /** @var array<string|int,mixed>|null $captured */
    $captured = null;

    $container = (new ContainerBuilder())
        ->addFactory(
            AuditMappedFactoryDto::class,
            static function (ContainerValue $_container, array $params) use (&$captured): AuditMappedFactoryDto {
                $captured = $params;
                $value = $params['value'] ?? '';
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('The mapped value must be a string.');
                }

                return new AuditMappedFactoryDto($value);
            },
        )
        ->build();

    $request = (new ServerRequest('POST', '/'))
        ->withParsedBody(['value' => 'payload']);

    $envelope = $container->make(AuditMappedFactoryEnvelope::class, [
        ServerRequestInterface::class => $request,
    ]);
    Assert::assertInstanceOf(AuditMappedFactoryEnvelope::class, $envelope);

    expect($envelope->dto->value)->toBe('payload')
        ->and($captured)->toBe(['value' => 'payload', ServerRequestInterface::class => $request]);
});

test('lazy service factories use the current ContainerValue runtime ABI', function (): void {
    $factory = new AuditLazyFactory();
    $container = (new ContainerBuilder())
        ->addFactory('audit.lazy', $factory)
        ->build();

    $first = $container->get('audit.lazy');
    Assert::assertInstanceOf(AuditLazyProduct::class, $first);
    expect($container->get('audit.lazy'))->toBe($first)
        ->and($factory->calls)->toBe(1)
        ->and($factory->initializations)->toBe(0)
        ->and($factory->seenContext)->toBe([]);

    $fresh = $container->make('audit.lazy', ['label' => 'caller']);
    Assert::assertInstanceOf(AuditLazyProduct::class, $fresh);
    expect($fresh)->not->toBe($first)
        ->and($factory->calls)->toBe(2)
        ->and($factory->initializations)->toBe(0)
        ->and($factory->seenContext)->toBe(['label' => 'caller'])
        ->and($factory->seenContainer)->toBeInstanceOf(ContainerValue::class)
        ->and($fresh->label)->toBe('caller')
        ->and($factory->initializations)->toBe(1)
        ->and($first->label)->toBe('default')
        ->and($factory->initializations)->toBe(2)
        ->and($container->get('audit.lazy'))->toBe($first)
        ->and($factory->calls)->toBe(2)
        ->and($first->label)->toBe('default')
        ->and($factory->initializations)->toBe(2);

    if (!$factory->seenContainer instanceof ContainerValue) {
        throw new \LogicException('The lazy factory did not receive ContainerValue.');
    }

    expect($factory->seenContainer->container)->toBe($container);
});
