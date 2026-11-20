<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestSelectedSources;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

abstract class RequestFixtures extends TestCase
{
    public static function queryWithUnavailableSharedSources(): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['value' => 'query']);
        $request->method('getAttributes')->willThrowException(new LogicException('Attributes were not selected.'));
        $request->method('getUploadedFiles')->willThrowException(new LogicException('Files were not selected.'));
        return $request;
    }
}

test('query mapping consumes shared request sources only when their selectors enable them', function (): void {
    $request = RequestFixtures::queryWithUnavailableSharedSources();
    $container = (new ContainerBuilder())->build();

    expect(new MapQueryString()->extract($request))->toBe(['value' => 'query'])
        ->and($container->call(
            static fn(#[MapQueryString] array $data): array => $data,
            [ServerRequestInterface::class => $request],
        ))->toBe(['value' => 'query']);

    expect(fn() => new MapRequest(sources: [RequestDataSource::Query], attributes: ['route'])->extract($request))
        ->toThrow(LogicException::class, 'Attributes were not selected.');
    expect(fn() => new MapRequest(sources: [RequestDataSource::Query], files: ['upload'])->extract($request))
        ->toThrow(LogicException::class, 'Files were not selected.');
});

#[Attribute(Attribute::TARGET_PARAMETER)]
final class FactoryMappedQuery extends MapQueryString {}

final readonly class MappedRecord
{
    public function __construct(public string $value) {}
}

test('a request mapper leaves deferred DTO availability to its configured factory', function (): void {
    $deferredClass = __NAMESPACE__ . '\\DeferredRecord_' . bin2hex(random_bytes(6));
    $factory = new class ($deferredClass) implements FactoryInterface {
        public function __construct(private readonly string $deferredClass) {}

        public function make(string $entry, array $params = []): object
        {
            if ($entry !== $this->deferredClass) {
                throw new LogicException('Unexpected mapped DTO type.');
            }
            if (!class_exists($entry, false)) {
                class_alias(MappedRecord::class, $entry);
            }
            $value = $params['value'] ?? null;
            if (!is_string($value)) {
                throw new LogicException('Expected the mapped query value.');
            }
            return new MappedRecord($value);
        }
    };
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            FactoryMappedQuery::class,
            new RequestAttributeHandler($factory, new TestCasterProvider()),
            [ValueProvider::class],
        ))
        ->build();
    $target = eval(sprintf(
        'return static fn(#[\\%s] \\%s $record): object => $record;',
        FactoryMappedQuery::class,
        $deferredClass,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected a typed mapping callback.');
    }

    expect(class_exists($deferredClass, false))->toBeFalse();
    $first = $container->call($target, [
        ServerRequestInterface::class => new ServerRequest('GET', '/')->withQueryParams(['value' => 'first']),
    ]);
    $second = $container->call($target, [
        ServerRequestInterface::class => new ServerRequest('GET', '/')->withQueryParams(['value' => 'second']),
    ]);

    if (!$first instanceof MappedRecord || !$second instanceof MappedRecord) {
        throw new LogicException('Expected both mapped DTOs.');
    }
    expect($first instanceof $deferredClass)->toBeTrue()
        ->and($first->value)->toBe('first')
        ->and($second instanceof $deferredClass)->toBeTrue()
        ->and($second->value)->toBe('second')
        ->and($second)->not->toBe($first);
});
