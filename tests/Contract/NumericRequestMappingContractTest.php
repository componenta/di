<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\MapQueryString;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\container;

test('request mapping preserves numeric field names instead of renumbering them', function (): void {
    $request = (new ServerRequest('GET', '/'))
        ->withQueryParams(['7' => 'selected', '0' => 'unrelated']);

    $result = container()->call(
        static fn(#[MapQueryString(map: ['7' => 'chosen'])] array $data): array => $data,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe([0 => 'unrelated', 'chosen' => 'selected']);
});
