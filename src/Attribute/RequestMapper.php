<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;

/** Base declarative transformation contract for all Map* request attributes. */
abstract class RequestMapper implements MapperInterface, CasterProviderAwareInterface
{
    private static ?RequestMapperPipeline $pipeline = null;

    public CasterProviderInterface $provider {
        get => $this->provider ??= new NullCasterProvider();
        set(CasterProviderInterface $value) {
            $this->provider = $value;
        }
    }

    /** @var array<string,string> */
    public protected(set) array $cast = [];
    /** @var array<string,mixed> */
    public protected(set) array $defaults = [];
    /** @var array<string,array<string,mixed>> */
    public protected(set) array $sortMap = [];
    /** @var list<string> */
    public protected(set) array $exclude = [];
    public protected(set) RequestDataConflictPolicy $conflictPolicy = RequestDataConflictPolicy::Reject;
    /** @var array<string,string> */
    public protected(set) array $map = [];

    /** @param array<string,string> $map */
    public function __construct(
        array $map = [],
        ?RequestDataConflictPolicy $conflictPolicy = null,
    ) {
        $this->map = array_merge($this->map, $map);
        if ($conflictPolicy !== null) {
            $this->conflictPolicy = $conflictPolicy;
        }
    }

    /** @param array<string,array<string|int,mixed>> $sources @return array<string|int,mixed> */
    protected function mergeRequestData(array $sources): array
    {
        $data = [];
        $owners = [];
        foreach ($sources as $source => $values) {
            foreach ($values as $key => $value) {
                if (!array_key_exists($key, $data)) {
                    $data[$key] = $value;
                    $owners[$key] = $source;
                    continue;
                }
                if ($data[$key] === $value
                    || $this->conflictPolicy === RequestDataConflictPolicy::FirstWins
                ) {
                    continue;
                }
                throw new RequestDataConflictException(
                    key: $key,
                    existingSource: $owners[$key],
                    incomingSource: $source,
                );
            }
        }
        return $data;
    }

    /** @param array<string|int,mixed> $data @return array<string|int,mixed> */
    public function transform(array $data): array
    {
        return (self::$pipeline ??= new RequestMapperPipeline())->run(
            $data,
            $this->map,
            $this->defaults,
            $this->cast,
            $this->sortMap,
            $this->exclude,
            $this->provider,
        );
    }
}
