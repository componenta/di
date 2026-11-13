<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestSourceAvailability;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
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

final readonly class DeclaredDto
{
    public function __construct(#[EntryId('trusted')] public string $value) {}
}

final class AutoloadPhase
{
    public bool $armed = false;
    public int $attempts = 0;
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ArmingMapper extends MapQueryString
{
    public static ?AutoloadPhase $phase = null;

    public function extract(ServerRequestInterface $request): array
    {
        if (self::$phase !== null) {
            self::$phase->armed = true;
        }
        return parent::extract($request);
    }
}

final class ObservingFactory implements FactoryInterface
{
    public int $calls = 0;

    public function make(string $entry, array $params = []): object
    {
        ++$this->calls;
        throw new LogicException('Conflicting data reached the DTO factory.');
    }
}

test('request mapping recognizes DTO sources loaded by the interface fallback before calling the factory', function (bool $lateDto): void {
    $suffix = bin2hex(random_bytes(5));
    $dto = 'Dto_' . $suffix;
    $source = 'Source_' . $suffix;
    $function = 'map_' . $suffix;
    $callable = __NAMESPACE__ . '\\' . $function;
    $lateClass = __NAMESPACE__ . '\\' . ($lateDto ? $dto : $source);
    if (!$lateDto) {
        eval(sprintf(
            'namespace %s; final readonly class %s { public function __construct(#[%s("trusted")] public string $value) {} }',
            __NAMESPACE__,
            $dto,
            $source,
        ));
    }
    eval(sprintf(
        'namespace %s; function %s(#[ArmingMapper] %s $dto): %s { return $dto; }',
        __NAMESPACE__,
        $function,
        $dto,
        $dto,
    ));

    $phase = new AutoloadPhase();
    ArmingMapper::$phase = $phase;
    $loader = static function (string $requested) use ($lateClass, $lateDto, $phase): void {
        if ($requested === $lateClass && $phase->armed && ++$phase->attempts === 2) {
            class_alias($lateDto ? DeclaredDto::class : EntryId::class, $lateClass);
        }
    };
    spl_autoload_register($loader);

    try {
        $factory = new ObservingFactory();
        $container = (new ContainerBuilder())
            ->addAttributeDefinition(new AttributeDefinition(
                ArmingMapper::class,
                new RequestAttributeHandler($factory, new TestCasterProvider()),
                [ValueProvider::class],
            ))
            ->build();
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'untrusted']);
        foreach (['first', 'repeated'] as $_invocation) {
            expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
                ->toThrow(RequestParameterSourceConflictException::class, 'value');
            expect($factory->calls)->toBe(0);
        }
        expect($phase->attempts)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
        ArmingMapper::$phase = null;
    }
})->with(['DTO class' => true, 'source attribute' => false]);
