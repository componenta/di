<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Caster\CasterExceptionInterface;
use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;

/**
 * Base class for all Map* attributes.
 *
 * Transforms raw request data into an associative array suitable for
 * constructing a Command/Query DTO via the DI container.
 *
 * ## Pipeline
 *
 * HTTP DTO resolution uses staged processing:
 * extractor -> validate raw data -> transform()
 *
 * transform() runs mapFields() -> cast -> defaults -> sortMap -> exclude.
 *
 * Extraction is handled by attributes implementing
 * {@see \Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface}.
 * This class stays focused on declaring transformation configuration.
 *
 * ## Declarative configuration
 *
 * Most mappers need zero code - just property overrides:
 *
 *     class MapUpdatePostCommand extends MapRequestPayload
 *     {
 *         protected array $attributes = ['id'];
 *         protected array $cast = ['id' => 'int'];
 *     }
 */
abstract class RequestMapper implements MapperInterface, CasterProviderAwareInterface
{
    /**
     * Stateless pipeline executor - shared across every Map* attribute
     * instance to avoid allocating a fresh one per request.
     */
    private static ?RequestMapperPipeline $pipeline = null;

    public CasterProviderInterface $provider {
        get => $this->provider ??= new NullCasterProvider();
        set(CasterProviderInterface $value) {
            $this->provider = $value;
        }
    }

    /**
     * Type casting rules applied after mapping, before defaults.
     *
     * Keys are target field names (after mapping), values are caster names
     * registered in {@see CasterProviderInterface} (e.g. 'int', 'bool',
     * 'datetime', 'rfc3339'). Casters run only when the key is present in
     * the data array - missing keys are filled by {@see $defaults} afterward.
     *
     * @var array<string, string>
     */
    protected array $cast = [];

    /**
     * Default values for missing keys (applied after cast).
     *
     * Defaults express the final field value, so their type must match the
     * target DTO property - not the raw HTTP input shape.
     *
     * @var array<string, mixed>
     */
    protected array $defaults = [];

    /**
     * Sort alias mapping: input value -> orderBy array.
     *
     * When non-empty, the `sort` key from data is looked up in this map.
     * If found, `orderBy` is set to the mapped value; otherwise `orderBy`
     * is set to null. The raw `sort` and `order` keys are always removed.
     *
     * Values must be arrays compatible with SortableInterface::$orderBy
     * (i.e. `array<non-empty-string, Direction>`).
     *
     * @var array<string, array>
     */
    protected array $sortMap = [];

    /**
     * Keys to exclude from the final data array.
     *
     * @var list<string>
     */
    protected array $exclude = [];

    /**
     * Field mapping rules: source key -> target key.
     *
     * Merged from the class-level default and constructor argument.
     * Prefix source key with `?` ({@see RequestMapperPipeline::OPTIONAL_PREFIX})
     * to make it optional (e.g. `'?slug' => 'slug'`).
     *
     * @var array<string, string>
     */
    protected(set) array $map = [];

    public function __construct(array $map = [])
    {
        $this->map = array_merge($this->map, $map);
    }

    /**
     * Applies mapper aliases, casts, defaults, sort aliases, and exclusions.
     *
     * @throws CasterExceptionInterface
     */
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
