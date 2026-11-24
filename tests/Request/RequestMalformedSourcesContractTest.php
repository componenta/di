<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestMalformedSourcesContract;

use Closure;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

enum ForeignSource: string
{
    case Query = 'query';
}

final class Calls
{
    public static int $bodies = 0;

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public static function consume(array $data): array
    {
        ++self::$bodies;
        return $data;
    }
}

abstract class RequestFixtures extends TestCase
{
    /** @param array<array-key,mixed> $uploads */
    public static function uploadedFiles(array $uploads): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getUploadedFiles')->willReturn($uploads);
        return $request;
    }
}

test('request source configuration rejects values outside its enum with a specific diagnostic', function (
    mixed $source,
    Closure $target,
): void {
    Calls::$bodies = 0;
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'current']);
    // @phpstan-ignore argument.type (Intentionally malformed public source configuration.)
    $mapper = new MapRequest(sources: [$source]);
    $message = sprintf('MapRequest sources must contain %s values; got %s.', RequestDataSource::class, get_debug_type($source));

    expect(fn(): array => $mapper->extract($request))->toThrow(InvalidArgumentException::class, $message);

    $container = (new ContainerBuilder())->build();
    foreach ([1, 2] as $_attempt) {
        $failure = null;
        try {
            $container->call($target, [ServerRequestInterface::class => $request]);
        } catch (ResolutionException $error) {
            $failure = $error;
        }
        expect($failure)->toBeInstanceOf(ResolutionException::class)
            ->and($failure?->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
            ->and($failure?->getPrevious()?->getMessage())->toBe($message)
            ->and(Calls::$bodies)->toBe(0);
    }

    expect($container->call(
        static fn(#[MapRequest(sources: [RequestDataSource::Query])] array $data): array => Calls::consume($data),
        [ServerRequestInterface::class => $request],
    ))->toBe(['value' => 'current'])->and(Calls::$bodies)->toBe(1);
})->with([
    'string with a valid backing value' => [
        'query',
        // @phpstan-ignore argument.type (Intentionally invalid attribute source.)
        static fn(#[MapRequest(sources: ['query'])] array $data): array => Calls::consume($data),
    ],
    'null' => [
        null,
        // @phpstan-ignore argument.type (Intentionally invalid attribute source.)
        static fn(#[MapRequest(sources: [null])] array $data): array => Calls::consume($data),
    ],
    'another enum with the same backing value' => [
        ForeignSource::Query,
        // @phpstan-ignore argument.type (Intentionally invalid attribute source.)
        static fn(#[MapRequest(sources: [ForeignSource::Query])] array $data): array => Calls::consume($data),
    ],
]);

test('upload extraction keeps its null fallback for malformed leaves returned by a PSR adapter', function (
    bool $dotted,
    mixed $leaf,
): void {
    // The adapter intentionally violates the PSR-7 upload-tree contract. This
    // exercises the existing defensive leaf boundary, not a valid upload shape.
    $files = $dotted ? ['group' => ['attachment' => $leaf]] : ['attachment' => $leaf];
    $request = RequestFixtures::uploadedFiles($files);
    $name = $dotted ? 'group.attachment' : 'attachment';
    $target = $dotted
        ? static fn(#[UploadedFile('group.attachment')] ?UploadedFileInterface $file): ?UploadedFileInterface => $file
        : static fn(#[UploadedFile('attachment')] ?UploadedFileInterface $file): ?UploadedFileInterface => $file;
    $container = (new ContainerBuilder())->build();

    expect((new UploadedFile($name))->extract($request))->toBeNull();
    foreach ([1, 2] as $_attempt) {
        expect($container->call($target, [ServerRequestInterface::class => $request]))->toBeNull();
    }
    expect($request->getUploadedFiles())->toBe($files);
})->with(['top-level leaf' => false, 'nested leaf' => true])
    ->with(['string' => 'invalid', 'integer' => 17, 'boolean' => false, 'object' => new \stdClass()]);
