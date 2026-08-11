<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Componenta\Caster\CasterExceptionInterface;
use Componenta\Caster\CasterNotFoundException;
use Componenta\Caster\CasterProviderInterface;
use InvalidArgumentException;

/**
 * Stateless executor for the data-transformation pipeline shared by every
 * {@see \Componenta\DI\Attribute\RequestMapper} subclass.
 *
 * Runs the transformation steps in the documented order:
 *
 *     mapFields -> cast -> defaults -> sortMap -> exclude
 *
 * Defaults are applied *after* cast so they express the final field value
 * (matching the DTO's declared type) rather than a synthetic raw input. This
 * also keeps casters from running on values the user never supplied.
 *
 * The pipeline owns no configuration of its own - it receives every knob from
 * the calling mapper, which keeps the attribute classes thin and the pipeline
 * trivial to test in isolation.
 *
 * @internal Container-internal collaborator; no external consumers.
 */
final readonly class RequestMapperPipeline
{
    /**
     * Optional-source-key prefix in {@see \Componenta\DI\Attribute\RequestMapper::$map}.
     * `'?key' => 'target'` - source is skipped when missing instead of raising.
     */
    public const string OPTIONAL_PREFIX = '?';

    /** Wildcard token recognised by request extraction attributes/files lists. */
    public const string WILDCARD = '*';

    /** Sort-aliasing input/output keys produced by the sortMap step. */
    public const string SORT_KEY = 'sort';
    public const string ORDER_KEY = 'order';
    public const string ORDER_BY_KEY = 'orderBy';

    /**
     * @param array<string, mixed>          $data
     * @param array<string, string>         $map      source-key -> target-key
     * @param array<string, mixed>          $defaults target-key -> default value
     * @param array<string, string>         $cast     target-key -> caster name
     * @param array<string, array>          $sortMap  sort-alias -> orderBy array
     * @param list<string>                  $exclude  target-keys to drop
     *
     * @throws InvalidArgumentException If a required mapped source key is missing.
     * @throws CasterExceptionInterface If a configured caster is not registered or fails.
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
            $data[self::ORDER_BY_KEY] = $sortMap[$data[self::SORT_KEY] ?? ''] ?? null;
            unset($data[self::SORT_KEY], $data[self::ORDER_KEY]);
        }

        foreach ($exclude as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * Renames keys as one atomic mapping operation.
     *
     * Every value is read from the original input before any source key is
     * removed. This makes swaps and chains deterministic and prevents an
     * earlier mapping from changing the value read by a later mapping.
     * Ambiguous target collisions are rejected instead of silently dropping
     * input.
     *
     * - `'source' => 'target'`  - required: missing source raises.
     * - `'?source' => 'target'` - optional: missing source is silently skipped.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $map
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function mapFields(array $data, array $map): array
    {
        /** @var list<array{from: string, to: string, value: mixed}> $moves */
        $moves = [];
        $mappedSources = [];
        $targetOwners = [];

        foreach ($map as $rawFrom => $rawTo) {
            $from = (string) $rawFrom;
            $to = (string) $rawTo;
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

                throw new InvalidArgumentException(
                    sprintf('Required key "%s" is missing', $from),
                );
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
            $moves[] = [
                'from' => $from,
                'to' => $to,
                'value' => $data[$from],
            ];
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
