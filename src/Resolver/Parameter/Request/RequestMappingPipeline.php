<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\Caster\CasterProviderInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Stateless request-data transformation pipeline shared by #[MapRequest].
 *
 * Order intentionally preserves the v4 contract:
 * map fields -> cast supplied fields -> defaults -> sort alias -> exclude.
 */
final readonly class RequestMappingPipeline
{
    public const string OPTIONAL_PREFIX = '?';
    public const string SORT_KEY = 'sort';
    public const string ORDER_KEY = 'order';
    public const string ORDER_BY_KEY = 'orderBy';

    /**
     * @param array<string|int, mixed> $data
     * @param array<string, string> $map
     * @param array<string, mixed> $defaults
     * @param array<string, string> $cast
     * @param array<string, array<string, mixed>> $sortMap
     * @param list<string> $exclude
     * @return array<string|int, mixed>
     */
    public function run(
        array $data,
        array $map,
        array $defaults,
        array $cast,
        array $sortMap,
        array $exclude,
        ?CasterProviderInterface $casters = null,
    ): array {
        if ($map !== []) {
            $data = $this->mapFields($data, $map);
        }

        if ($cast !== [] && $casters === null) {
            throw new InvalidArgumentException('Request mapping defines casts but no caster provider is configured.');
        }

        foreach ($cast as $key => $casterName) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $caster = $casters?->provide($casterName)
                ?? throw new LogicException(sprintf('Caster "%s" is not registered.', $casterName));
            $data[$key] = $caster->cast($data[$key]);
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
     * Renames fields atomically so swaps/chains read only from original input.
     *
     * @param array<string|int, mixed> $data
     * @param array<string, string> $map
     * @return array<string|int, mixed>
     */
    private function mapFields(array $data, array $map): array
    {
        $original = $data;
        /** @var list<array{0: string, 1: string, 2: mixed}> $moves */
        $moves = [];
        /** @var array<string, true> $mappedSources */
        $mappedSources = [];
        /** @var array<string, string> $targetOwners */
        $targetOwners = [];

        foreach ($map as $rawSource => $target) {
            $source = (string) $rawSource;
            $optional = str_starts_with($source, self::OPTIONAL_PREFIX);
            if ($optional) {
                $source = substr($source, 1);
            }

            if ($source === '' || $target === '') {
                throw new InvalidArgumentException('Request mapping source and target keys must be non-empty strings.');
            }

            if (!array_key_exists($source, $original)) {
                if ($optional) {
                    continue;
                }
                throw new InvalidArgumentException(sprintf('Required mapped key "%s" is missing.', $source));
            }

            if (isset($targetOwners[$target]) && $targetOwners[$target] !== $source) {
                throw new InvalidArgumentException(sprintf('Mapped target "%s" has multiple sources.', $target));
            }

            $mappedSources[$source] = true;
            $targetOwners[$target] = $source;
            $moves[] = [$source, $target, $original[$source]];
        }

        foreach ($moves as [$source, $target]) {
            if ($source !== $target
                && array_key_exists($target, $original)
                && !isset($mappedSources[$target])
            ) {
                throw new InvalidArgumentException(sprintf('Mapped target "%s" already exists.', $target));
            }
        }

        foreach ($moves as [$source, $target]) {
            if ($source !== $target) {
                unset($data[$source]);
            }
        }

        foreach ($moves as [, $target, $value]) {
            $data[$target] = $value;
        }

        return $data;
    }
}
