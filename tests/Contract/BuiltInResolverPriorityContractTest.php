<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;

interface PriorityBoundaryContract
{
    public function label(): string;
}

final readonly class PriorityBoundaryProduct implements PriorityBoundaryContract
{
    public function __construct(private string $source = 'native') {}

    public function label(): string
    {
        return $this->source;
    }
}

final readonly class PriorityBoundaryResolver implements ParameterResolverInterface
{
    public function __construct(private mixed $value) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, $this->value];
    }
}

test(
    'custom resolver priorities can interpose immediately above each built-in resolution stage',
    /** @param array<array-key,mixed> $params */
    function (Closure $callable, array $params, mixed $custom, mixed $nativeResult, mixed $customResult, int $priority, int $offset): void {
        $container = (new ContainerBuilder(new Config(['value' => 7], new Environment([]))))
            ->addParameterResolver(new PriorityBoundaryResolver($custom), $priority + $offset)
            ->build();

        expect($container->call($callable, $params))->toBe($offset === 0 ? $nativeResult : $customResult);
    },
)->with([
    'attribute source' => [static fn(#[ConfigAttribute('value')] int $value): int => $value, [], 9, 7, 9, 1200],
    'explicit argument' => [static fn(int $value): int => $value, ['value' => 7], 9, 7, 9, 1100],
    'type-keyed argument' => [
        static fn(PriorityBoundaryProduct $value): string => $value->label(),
        [PriorityBoundaryProduct::class => new PriorityBoundaryProduct('provided')],
        new PriorityBoundaryProduct('custom'),
        'provided', 'custom', 1000,
    ],
    'autowired argument' => [
        static fn(PriorityBoundaryProduct $value): string => $value->label(), [],
        new PriorityBoundaryProduct('custom'), 'native', 'custom', 300,
    ],
    'declared default' => [static fn(int $value = 7): int => $value, [], 9, 7, 9, 200],
    'nullable fallback' => [
        static fn(?PriorityBoundaryContract $value): ?string => $value?->label(), [],
        new PriorityBoundaryProduct('custom'), null, 'custom', 100,
    ],
])->with(['same priority preserves built-in precedence' => 0, 'next priority interposes' => 1]);
