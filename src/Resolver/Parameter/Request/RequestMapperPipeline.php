<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\Caster\CasterNotFoundException;
use Componenta\Caster\CasterProviderInterface;
use InvalidArgumentException;

/** Stateless shared request mapper transformation pipeline. */
final readonly class RequestMapperPipeline
{
    public const string OPTIONAL_PREFIX = '?';
    public const string WILDCARD = '*';
    public const string SORT_KEY = 'sort';
    public const string ORDER_KEY = 'order';
    public const string ORDER_BY_KEY = 'orderBy';

    /**
     * @param array<string|int,mixed> $data
     * @param array<string,string> $map
     * @param array<string,mixed> $defaults
     * @param array<string,string> $cast
     * @param array<string,array<string,mixed>> $sortMap
     * @param list<string> $exclude
     * @return array<string|int,mixed>
     */
    public function run(
        array $data,
        array $map,
        array $defaults,
        array $cast,
        array $sortMap,
        array $exclude,
        CasterProviderInterface $provider,
    ): array {
        if ($map !== []) {
            $data = $this->mapFields($data, $map);
        }

        foreach ($cast as $key => $casterName) {
            if (array_key_exists($key, $data)) {
                $caster = $provider->provide($casterName)
                    ?? throw CasterNotFoundException::forName($casterName);
                $data[$key] = $caster->cast($data[$key]);
            }
        }

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        if ($sortMap !== []) {
            if (!array_key_exists(self::SORT_KEY, $data)) {
                $data[self::ORDER_BY_KEY] = null;
            } else {
                $sort = $data[self::SORT_KEY];
                if (!is_string($sort) && !is_int($sort)) {
                    throw new InvalidArgumentException(sprintf(
                        'Sort alias must be a string or integer; got %s.',
                        get_debug_type($sort),
                    ));
                }
                $data[self::ORDER_BY_KEY] = $sortMap[(string) $sort] ?? null;
            }
            unset($data[self::SORT_KEY], $data[self::ORDER_KEY]);
        }

        foreach ($exclude as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * @param array<string|int,mixed> $data
     * @param array<string,string> $map
     * @return array<string|int,mixed>
     */
    private function mapFields(array $data, array $map): array
    {
        /** @var list<array{from:string,to:string,value:mixed}> $moves */
        $moves = [];
        /** @var array<string,true> $mappedSources */
        $mappedSources = [];
        /** @var array<string,string> $targetOwners */
        $targetOwners = [];

        foreach ($map as $rawFrom => $rawTo) {
            $from = (string) $rawFrom;
            $to = $rawTo;
            $optional = $from !== '' && $from[0] === self::OPTIONAL_PREFIX;
            if ($optional) {
                $from = substr($from, 1);
            }
            if ($to === '') {
                throw new InvalidArgumentException('Mapped target key cannot be empty');
            }
            if (!array_key_exists($from, $data)) {
                if ($optional) {
                    continue;
                }
                throw new InvalidArgumentException(sprintf('Required key "%s" is missing', $from));
            }
            if (isset($targetOwners[$to]) && $targetOwners[$to] !== $from) {
                throw new InvalidArgumentException(sprintf(
                    'Mapped target key "%s" is produced by both "%s" and "%s"',
                    $to,
                    $targetOwners[$to],
                    $from,
                ));
            }
            $targetOwners[$to] = $from;
            $mappedSources[$from] = true;
            $moves[] = ['from' => $from, 'to' => $to, 'value' => $data[$from]];
        }

        foreach ($moves as $move) {
            if ($move['from'] !== $move['to']
                && array_key_exists($move['to'], $data)
                && !isset($mappedSources[$move['to']])
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Mapped target key "%s" already exists in input',
                    $move['to'],
                ));
            }
        }

        foreach ($moves as $move) {
            if ($move['from'] !== $move['to']) {
                unset($data[$move['from']]);
            }
        }
        foreach ($moves as $move) {
            $data[$move['to']] = $move['value'];
        }
        return $data;
    }
}
