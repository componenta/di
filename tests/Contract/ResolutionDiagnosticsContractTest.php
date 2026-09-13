<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use RuntimeException;

final class DiagnosticCallable
{
    /** @return array{int,string} */
    public static function invoke(int $count, string $value): array
    {
        return [$count, $value];
    }
}

final class DiagnosticFailingResolver implements ParameterResolverInterface
{
    public bool $fail = true;

    public function __construct(public readonly RuntimeException $failure) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        if ($this->fail) {
            throw $this->failure;
        }
        return null;
    }
}

test('resolution diagnostics preserve supplied and completed parameter types and the original cause', function (): void {
    $cause = new RuntimeException('extension unavailable');
    $resolver = new DiagnosticFailingResolver($cause);
    $container = (new ContainerBuilder())->addParameterResolver($resolver, 2100)->build();

    try {
        $container->call([DiagnosticCallable::class, 'invoke'], [3, 'provided']);
        \PHPUnit\Framework\Assert::fail('The failing extension must interrupt resolution.');
    } catch (ResolutionException $exception) {
        expect($exception->parameterName)->toBe('value')
            ->and($exception->parameterPosition)->toBe(1)
            ->and($exception->parameterType)->toBe('string')
            ->and($exception->parameterContext)->toBe(DiagnosticCallable::class . '::invoke()')
            ->and($exception->providedParameterTypes)->toBe([0 => 'int', 1 => 'string'])
            ->and($exception->resolvedParameterTypes)->toBe([0 => 'int'])
            ->and($exception->getMessage())->toBe('Cannot resolve parameter "$value" of ' . DiagnosticCallable::class . '::invoke(): extension unavailable')
            ->and($exception->getPrevious())->toBe($cause)
            ->and($exception->getCode())->toBe(0);
    }

    $resolver->fail = false;
    expect($container->call([DiagnosticCallable::class, 'invoke'], [3, 'provided']))->toBe([3, 'provided']);
});
