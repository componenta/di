<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

/** Maps one or more PSR-7 request sources into an array or class-typed DTO. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapRequest extends RequestMapper implements RequestDataExtractorInterface
{
    /** Wildcard for selecting all request attributes or uploaded files. */
    public const string ALL = '*';

    /** @var list<RequestDataSource> */
    public protected(set) array $sources;
    /** @var list<string> */
    public protected(set) array $attributes;
    /** @var list<string> */
    public protected(set) array $files;

    /**
     * @param list<RequestDataSource> $sources
     * @param array<string,string> $map
     * @param list<string> $exclude
     * @param array<string,mixed> $defaults
     * @param array<string,string> $cast
     * @param array<string,array<string,mixed>> $sortMap
     * @param list<string> $attributes
     * @param list<string> $files
     */
    public function __construct(
        array $sources = [RequestDataSource::Payload],
        array $map = [],
        array $exclude = [],
        RequestDataConflictPolicy $conflictPolicy = RequestDataConflictPolicy::Reject,
        array $defaults = [],
        array $cast = [],
        array $sortMap = [],
        array $attributes = [],
        array $files = [],
    ) {
        parent::__construct($map, $conflictPolicy);

        $this->sources = array_values($sources);
        $this->exclude = array_values($exclude);
        $this->defaults = $defaults;
        $this->cast = $cast;
        $this->sortMap = $sortMap;
        $this->attributes = array_values($attributes);
        $this->files = array_values($files);
    }

    /** @return array<string|int,mixed> */
    public function extract(ServerRequestInterface $request): array
    {
        $sources = [];
        $seen = [];

        // Preserve the v5 convenience: selected attributes/files may be added
        // alongside the declared primary sources without repeating the enum.
        if ($this->attributes !== []
            && !in_array(RequestDataSource::Attributes, $this->sources, true)
        ) {
            $sources['request attributes'] = $this->select(
                $request->getAttributes(),
                $this->attributes,
                'request attributes',
            );
        }
        if ($this->files !== []
            && !in_array(RequestDataSource::Files, $this->sources, true)
        ) {
            $sources['uploaded files'] = $this->select(
                $request->getUploadedFiles(),
                $this->files,
                'uploaded files',
            );
        }

        foreach ($this->sources as $source) {
            if (!$source instanceof RequestDataSource) {
                throw new InvalidArgumentException(sprintf(
                    'MapRequest sources must contain %s values; got %s.',
                    RequestDataSource::class,
                    get_debug_type($source),
                ));
            }
            if (isset($seen[$source->value])) {
                throw new InvalidArgumentException(sprintf(
                    'MapRequest source "%s" is declared more than once.',
                    $source->value,
                ));
            }
            $seen[$source->value] = true;

            $values = $this->source($source, $request);
            if ($source === RequestDataSource::Attributes && $this->attributes !== []) {
                $values = $this->select($values, $this->attributes, 'request attributes');
            } elseif ($source === RequestDataSource::Files && $this->files !== []) {
                $values = $this->select($values, $this->files, 'uploaded files');
            }

            $sources[$this->sourceLabel($source)] = $values;
        }

        return $this->mergeRequestData($sources);
    }

    /** @return array<string|int,mixed> */
    private function source(RequestDataSource $source, ServerRequestInterface $request): array
    {
        return match ($source) {
            RequestDataSource::Payload => $this->payload($request),
            RequestDataSource::Query => $request->getQueryParams(),
            RequestDataSource::Headers => array_map(
                static fn(array $values): string => implode(', ', $values),
                $request->getHeaders(),
            ),
            RequestDataSource::Cookies => $request->getCookieParams(),
            RequestDataSource::Attributes => $request->getAttributes(),
            RequestDataSource::Server => $request->getServerParams(),
            RequestDataSource::Files => $request->getUploadedFiles(),
        };
    }

    /** @return array<string|int,mixed> */
    private function payload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if ($body === null) {
            return [];
        }

        return is_array($body) ? $body : get_object_vars($body);
    }

    /**
     * @param array<string|int,mixed> $values
     * @param list<string> $selection
     * @return array<string|int,mixed>
     */
    private function select(array $values, array $selection, string $source): array
    {
        if ($selection === [self::ALL]) {
            return $values;
        }

        $selected = [];
        foreach ($selection as $key) {
            if ($key === self::ALL) {
                throw new InvalidArgumentException(sprintf(
                    '%s wildcard must be the only selector.',
                    ucfirst($source),
                ));
            }
            if (array_key_exists($key, $values)) {
                $selected[$key] = $values[$key];
            }
        }
        return $selected;
    }

    private function sourceLabel(RequestDataSource $source): string
    {
        return match ($source) {
            RequestDataSource::Payload => 'parsed body',
            RequestDataSource::Query => 'query string',
            RequestDataSource::Headers => 'headers',
            RequestDataSource::Cookies => 'cookies',
            RequestDataSource::Attributes => 'request attributes',
            RequestDataSource::Server => 'server parameters',
            RequestDataSource::Files => 'uploaded files',
        };
    }
}
