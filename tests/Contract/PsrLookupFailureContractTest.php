<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PsrLookupFailure;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final readonly class Label
{
    public function __construct(#[EntryId('palette.label')] public string $value) {}
}

test('get distinguishes a missing requested entry from a missing dependency of a selected entry', function (): void {
    $container = (new ContainerBuilder())->addAlias('theme.label', Label::class)->build();
    expect($container->has('theme.label'))->toBeTrue()
        ->and($container->has('unknown.label'))->toBeFalse();

    $error = null;
    try {
        $container->get('theme.label');
    } catch (ResolutionException $failure) {
        $error = $failure;
    }

    if ($error === null) {
        throw new LogicException('Expected a service resolution failure.');
    }
    $cause = $error->getPrevious();
    if (!$cause instanceof NotFoundException) {
        throw new LogicException('Expected the original missing dependency.');
    }
    expect($error->serviceId)->toBe('theme.label')
        ->and($cause->id)->toBe('palette.label')
        ->and(fn() => $container->get('unknown.label'))->toThrow(NotFoundException::class, 'unknown.label')
        ->and(fn() => $container->make(Label::class))->toThrow(NotFoundException::class, 'palette.label');

    $container->set('palette.label', 'blue');
    expect($container->get('theme.label'))->toEqual(new Label('blue'));
});

test('a selected delegator may fail because its own entry is missing', function (): void {
    $container = (new ContainerBuilder())->addService('product', new \stdClass())->build();
    $container->delegator('product', static fn(): mixed => $container->get('missing.decorator.dependency'));

    expect($container->has('product'))->toBeTrue()
        ->and(fn() => $container->get('product'))->toThrow(ResolutionException::class, 'missing.decorator.dependency');
});
