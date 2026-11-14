<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestSourceDiscovery;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Metadata {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class DiscoveryMapper extends MapQueryString {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

abstract class Dto
{
    public string $value;
}

final class ObservingFactory implements FactoryInterface
{
    public int $calls = 0;

    public function make(string $entry, array $params = []): object
    {
        ++$this->calls;
        if (!is_a($entry, Dto::class, true)) {
            throw new LogicException('Expected the request source discovery DTO.');
        }
        $dto = new $entry();
        $value = $params['value'] ?? null;
        if (!is_string($value)) {
            throw new LogicException('Expected the mapped string value.');
        }
        $dto->value = $value;
        return $dto;
    }
}

test('request mapping discovers earlier sources loaded by later metadata before dispatching a DTO factory', function (bool $laterParameter, bool $uncached, bool $warm): void {
    $suffix = bin2hex(random_bytes(5));
    $dto = 'Dto_' . $suffix;
    $source = 'Source_' . $suffix;
    $metadata = 'Metadata_' . $suffix;
    $function = 'map_' . $suffix;
    $parameters = $laterParameter
        ? sprintf('#[%s("trusted")] public string $value = "default", #[%s] string $other = "default"', $source, $metadata)
        : sprintf('#[%s("trusted"), %s] public string $value = "default"', $source, $metadata);
    eval(sprintf(
        'namespace %s; final class %s extends Dto { public function __construct(%s) {} } function %s(#[DiscoveryMapper] %s $dto): %s { return $dto; }',
        __NAMESPACE__,
        $dto,
        $parameters,
        $function,
        $dto,
        $dto,
    ));

    $factory = new ObservingFactory();
    $builder = (new ContainerBuilder())->addAttributeDefinition(new AttributeDefinition(
        DiscoveryMapper::class,
        new RequestAttributeHandler($factory, new TestCasterProvider()),
        [ValueProvider::class],
    ));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(Metadata::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $callable = __NAMESPACE__ . '\\' . $function;
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'untrusted']);
    if ($warm) {
        $result = $container->call($callable, [ServerRequestInterface::class => $request]);
        expect($result)->toBeInstanceOf(Dto::class);
        if (!$result instanceof Dto) {
            throw new LogicException('Expected the warm DTO.');
        }
        expect($result->value)->toBe('untrusted');
    }
    $callsBeforeDiscovery = $factory->calls;

    $loads = 0;
    $sourceAttempts = 0;
    $loader = static function (string $requested) use ($source, $metadata, &$loads, &$sourceAttempts): void {
        if ($requested === __NAMESPACE__ . '\\' . $source) {
            ++$sourceAttempts;
        }
        if ($requested === __NAMESPACE__ . '\\' . $metadata) {
            ++$loads;
            class_alias(EntryId::class, __NAMESPACE__ . '\\' . $source);
            class_alias(Metadata::class, __NAMESPACE__ . '\\' . $metadata);
        }
    };
    spl_autoload_register($loader);

    try {
        foreach (['first', 'repeated'] as $_invocation) {
            expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
                ->toThrow(RequestParameterSourceConflictException::class, 'value');
            expect($factory->calls)->toBe($callsBeforeDiscovery);
        }
        expect($loads)->toBe(1)
            ->and($sourceAttempts)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['same parameter' => false, 'later parameter' => true])
    ->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);
