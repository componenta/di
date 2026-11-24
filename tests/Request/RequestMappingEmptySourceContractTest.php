<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestMappingEmptySourceContract;

use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

test('MapRequest renames an empty source key without emitting warnings', function (): void {
    $request = (new ServerRequest('POST', '/items'))->withParsedBody([
        '' => 'source-value',
        'untouched' => 'kept',
    ]);
    $container = (new ContainerBuilder())->build();
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    }, E_WARNING);

    try {
        $mapped = $container->call(
            static fn(#[MapRequest(map: ['' => 'named'])] array $data): array => $data,
            [ServerRequestInterface::class => $request],
        );
    } finally {
        restore_error_handler();
    }

    expect($mapped)->toBe(['untouched' => 'kept', 'named' => 'source-value'])
        ->and($warnings)->toBe([]);
});
