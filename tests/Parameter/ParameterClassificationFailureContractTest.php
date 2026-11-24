<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\ParameterClassificationFailureContract;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use RuntimeException;
use Throwable;

final class FailingClassifier implements ParameterResolverInterface
{
    public int $resolutions = 0;

    public function __construct(public ?Throwable $failure) {}

    public function supports(ParameterTarget $target): bool
    {
        if ($target->name !== 'value') {
            return false;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return true;
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        ++$this->resolutions;
        return [$target->position, 'resolved'];
    }
}

test('classification failures preserve ownership and leave callable resolution retryable', function (bool $owned): void {
    $failure = $owned ? new NotFoundException('classifier.metadata') : new RuntimeException('metadata unavailable');
    $classifier = new FailingClassifier($failure);
    $container = (new ContainerBuilder())->addParameterResolver($classifier, 2000)->build();
    $bodyCalls = 0;
    $callback = static function (string $value) use (&$bodyCalls): string {
        ++$bodyCalls;
        return $value;
    };
    $caught = null;

    try {
        $container->call($callback);
    } catch (ExceptionInterface $actual) {
        $caught = $actual;
    }

    expect($classifier->resolutions)->toBe(0)
        ->and($bodyCalls)->toBe(0);
    if ($owned) {
        expect($caught)->toBe($failure);
    } else {
        if (!$caught instanceof ResolutionException) {
            throw new LogicException('A foreign classification failure must be normalized.', previous: $caught);
        }
        expect($caught->getPrevious())->toBe($failure)
            ->and($caught->parameterName)->toBe('value')
            ->and($caught->parameterPosition)->toBe(0)
            ->and($caught->parameterType)->toBe('string')
            ->and($caught->getMessage())->toContain('parameter resolver classification failed');
    }

    $classifier->failure = null;
    expect($container->call($callback))->toBe('resolved')
        ->and($classifier->resolutions)->toBe(1)
        ->and($bodyCalls)->toBe(1)
        ->and($container->call($callback))->toBe('resolved')
        ->and($classifier->resolutions)->toBe(2)
        ->and($bodyCalls)->toBe(2);
})->with(['owned' => true, 'foreign' => false]);
