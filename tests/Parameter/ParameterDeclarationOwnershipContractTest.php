<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\ParameterDeclarationOwnershipContract;

use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\Resolver\Parameter\ParameterTargetAccess;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionParameter;

trait Declaration
{
    public function label(string $label = 'declared default'): string
    {
        return $label;
    }
}

final class Receiver
{
    use Declaration;

    public static function alternative(string $replacement = 'alternate default'): string
    {
        return $replacement;
    }
}

final class ReflectorObserver implements ParameterResolverInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $legacyAccessor) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'label';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array
    {
        ++$this->calls;
        $access = [ParameterTargetAccess::declaration($target), $this->legacyAccessor];
        $reflection = is_callable($access) ? $access() : $target->reflection;
        if (!$reflection instanceof ReflectionParameter) {
            throw new LogicException('Expected the parameter reflector from the declaration.');
        }
        $reflection->__construct([Receiver::class, 'alternative'], 0);
        return null;
    }
}

test('a collaborator cannot change the native default through declaration access', function (bool $closure): void {
    $observer = new ReflectorObserver('metadataTarget');
    $container = (new ContainerBuilder())->addParameterResolver($observer, 1050)->build();
    $receiver = new Receiver();
    $read = $closure ? $receiver->label(...) : [$receiver, 'label'];

    expect($container->call($read, ['label' => 'explicit']))->toBe('explicit')
        ->and($observer->calls)->toBe(0);

    foreach ([1, 2] as $attempt) {
        expect($container->call($read))->toBe('declared default')
            ->and($observer->calls)->toBe($attempt);
    }
})->with(['trait method array' => false, 'trait method capture' => true]);

test('variadic validation uses the original signature after the input reflector is retargeted', function (): void {
    [, $runtime] = (new ContainerBuilder())->assemble();
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $original = static fn(int $fixed, int ...$values): array => $values;
    $replacement = static fn(int $alternate, int ...$values): array => $values;
    $input = new ReflectionParameter($original, 'values');
    $target = $parameters->target($input);
    $input->__construct($replacement, 'values');

    $valid = new ParameterResolutionContext(provided: ['values' => ['alternate' => 5]]);
    $collision = new ParameterResolutionContext(provided: ['values' => ['fixed' => 5]]);

    expect($parameters->resolveParameter($target, $valid))->toBe([1, ['alternate' => 5]])
        ->and(fn() => $parameters->resolveParameter($target, $collision))
        ->toThrow(ResolutionException::class, 'variadic argument "fixed" would overwrite a preceding parameter');
});
