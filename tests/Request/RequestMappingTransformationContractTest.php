<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestMappingTransformationContract;

use Attribute;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\Validation\Exception\ValidationException;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class ConstructionProbe
{
    public static int $calls = 0;
}

final readonly class Profile
{
    public function __construct(
        public string $displayName,
        #[EntryId('trusted-role')] public string $role,
    ) {
        ++ConstructionProbe::$calls;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class NormalizedQuery extends MapQueryString
{
    public static int $calls = 0;

    public function transform(array $data): array
    {
        ++self::$calls;
        $data = parent::transform($data);
        $given = $data['given'] ?? null;
        $family = $data['family'] ?? null;
        if (!is_string($given) || !is_string($family)) {
            throw new \LogicException('The normalization fixture expects two name fields.');
        }

        return ['displayName' => trim($given) . ' ' . trim($family)];
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ProtectedNameQuery extends MapQueryString
{
    public function transform(array $data): array
    {
        $data = parent::transform($data);
        $data['role'] = $data['externalRole'] ?? null;
        unset($data['externalRole']);

        return $data;
    }
}

function normalized(#[NormalizedQuery] Profile $profile): Profile
{
    return $profile;
}

function excluded(
    #[MapRequest(sources: [RequestDataSource::Query], exclude: ['role'])] Profile $profile,
): Profile {
    return $profile;
}

function renamed(
    #[MapRequest(sources: [RequestDataSource::Query], map: ['externalRole' => 'role'])] Profile $profile,
): Profile {
    return $profile;
}

function transformed(#[ProtectedNameQuery] Profile $profile): Profile
{
    return $profile;
}

test('casting and sorting preserve the next append key after a numeric input is renamed', function (bool $sorting): void {
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
    $raw = [0 => 'first', 20 => ' last '];
    $request = (new ServerRequest('GET', '/'))->withQueryParams($raw);
    $target = $sorting
        ? static fn(
            #[MapRequest(sources: [RequestDataSource::Query], map: [20 => 'name'], sortMap: ['name' => ['name' => 'ASC']])]
            array $data,
        ): array => $data
        : static fn(
            #[MapRequest(sources: [RequestDataSource::Query], map: [20 => 'name'], cast: ['name' => 'trim'])]
            array $data,
        ): array => $data;
    $mapped = $container->call($target, [ServerRequestInterface::class => $request]);
    if (!is_array($mapped)) {
        throw new \LogicException('Expected mapped array data.');
    }
    $mapped[] = 'next';

    expect($mapped)->toBe($sorting
        ? [0 => 'first', 'name' => ' last ', 'orderBy' => null, 21 => 'next']
        : [0 => 'first', 'name' => 'last', 21 => 'next'])
        ->and($request->getQueryParams())->toBe($raw);
})->with(['cast' => false, 'sort' => true]);

test('whole-array request transformation supplies readonly constructor fields without retaining removed source inputs', function (): void {
    ConstructionProbe::$calls = 0;
    NormalizedQuery::$calls = 0;
    $container = (new ContainerBuilder())->addService('trusted-role', 'reader')->build();

    foreach (['first', 'second'] as $family) {
        $data = ['given' => ' Ada ', 'family' => ' ' . $family . ' ', 'role' => 'administrator'];
        $request = (new ServerRequest('GET', '/'))->withQueryParams($data);
        $profile = $container->call(__NAMESPACE__ . '\\normalized', [ServerRequestInterface::class => $request]);

        expect($profile)->toBeInstanceOf(Profile::class);
        if (!$profile instanceof Profile) {
            throw new \LogicException('Expected the mapped readonly profile.');
        }
        expect([$profile->displayName, $profile->role])->toBe(['Ada ' . $family, 'reader'])
            ->and($request->getQueryParams())->toBe($data);
    }

    expect(NormalizedQuery::$calls)->toBe(2)
        ->and(ConstructionProbe::$calls)->toBe(2);
});

test('excluding a raw source-shaped field preserves the trusted constructor source', function (): void {
    ConstructionProbe::$calls = 0;
    $container = (new ContainerBuilder())->addService('trusted-role', 'reader')->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams([
        'displayName' => 'Ada',
        'role' => 'administrator',
    ]);

    foreach ([1, 2] as $_attempt) {
        $profile = $container->call(__NAMESPACE__ . '\\excluded', [ServerRequestInterface::class => $request]);
        expect($profile)->toBeInstanceOf(Profile::class);
        if (!$profile instanceof Profile) {
            throw new \LogicException('Expected the mapped readonly profile.');
        }
        expect([$profile->displayName, $profile->role])->toBe(['Ada', 'reader']);
    }

    expect(ConstructionProbe::$calls)->toBe(2);
});

test('renaming request data into an explicit source fails before DTO construction', function (string $function): void {
    ConstructionProbe::$calls = 0;
    $container = (new ContainerBuilder())->addService('trusted-role', 'reader')->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams([
        'displayName' => 'Ada',
        'externalRole' => 'administrator',
    ]);

    foreach ([1, 2] as $_attempt) {
        expect(fn() => $container->call(__NAMESPACE__ . '\\' . $function, [ServerRequestInterface::class => $request]))
            ->toThrow(RequestParameterSourceConflictException::class, 'role');
    }

    expect(ConstructionProbe::$calls)->toBe(0);
})->with(['declarative rename' => 'renamed', 'custom whole-array transform' => 'transformed']);

test('raw validation precedes whole-array transformation and remains retryable', function (): void {
    ConstructionProbe::$calls = 0;
    NormalizedQuery::$calls = 0;
    $validation = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return $entryId === Profile::class
                ? new Validator(['given' => new Required()])
                : null;
        }
    };
    $container = (new ContainerBuilder())
        ->addService('trusted-role', 'reader')
        ->addService(ValidationProviderInterface::class, $validation)
        ->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['given' => '', 'family' => 'Lovelace']);

    try {
        $container->call(__NAMESPACE__ . '\\normalized', [ServerRequestInterface::class => $request]);
        \PHPUnit\Framework\Assert::fail('Raw validation must fail before transformation.');
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBeInstanceOf(ValidationException::class)
            ->and(NormalizedQuery::$calls)->toBe(0)
            ->and(ConstructionProbe::$calls)->toBe(0);
    }

    $profile = $container->call(__NAMESPACE__ . '\\normalized', [
        ServerRequestInterface::class => $request->withQueryParams(['given' => 'Ada', 'family' => 'Lovelace']),
    ]);
    expect($profile)->toBeInstanceOf(Profile::class);
    if (!$profile instanceof Profile) {
        throw new \LogicException('Expected the validated readonly profile.');
    }
    expect([$profile->displayName, $profile->role])->toBe(['Ada Lovelace', 'reader'])
        ->and(NormalizedQuery::$calls)->toBe(1)
        ->and(ConstructionProbe::$calls)->toBe(1);
});
