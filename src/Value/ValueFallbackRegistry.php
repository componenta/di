<?php

declare(strict_types=1);

namespace Componenta\DI\Value;

use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

/** Ordered fallback registry with stable topological composition. */
final class ValueFallbackRegistry
{
    /** @var array<non-empty-string, array{definition: ValueFallbackDefinition, order: int}> */
    private array $items = [];

    /** @var list<ValueFallbackDefinition>|null */
    private ?array $ordered = null;

    private bool $sealed = false;

    public function add(ValueFallbackDefinition $definition): void
    {
        if ($this->sealed) {
            throw new LogicException('Value fallback registry is sealed.');
        }
        if (isset($this->items[$definition->id])) {
            throw new InvalidConfigurationException(sprintf('Value fallback id "%s" is already registered.', $definition->id));
        }

        $this->items[$definition->id] = [
            'definition' => $definition,
            'order' => count($this->items),
        ];
        $this->ordered = null;
    }

    public function seal(): void
    {
        $this->definitions();
        $this->sealed = true;
    }

    /** @return list<ValueFallbackInterface> */
    public function fallbacks(): array
    {
        return array_map(
            static fn(ValueFallbackDefinition $definition): ValueFallbackInterface => $definition->fallback,
            $this->definitions(),
        );
    }

    /** @return list<ValueFallbackDefinition> */
    public function definitions(): array
    {
        if ($this->ordered !== null) {
            return $this->ordered;
        }
        if ($this->items === []) {
            return $this->ordered = [];
        }

        $edges = [];
        $indegree = array_fill_keys(array_keys($this->items), 0);
        foreach ($this->items as $id => $item) {
            foreach ($item['definition']->before as $other) {
                $this->assertKnown($id, $other);
                self::edge($edges, $indegree, $id, $other);
            }
            foreach ($item['definition']->after as $other) {
                $this->assertKnown($id, $other);
                self::edge($edges, $indegree, $other, $id);
            }
        }

        $remaining = array_fill_keys(array_keys($this->items), true);
        $result = [];
        while ($remaining !== []) {
            $next = null;
            foreach ($remaining as $id => $_) {
                if ($indegree[$id] !== 0) {
                    continue;
                }
                if ($next === null || $this->items[$id]['order'] < $this->items[$next]['order']) {
                    $next = $id;
                }
            }

            if ($next === null) {
                throw new InvalidConfigurationException(sprintf(
                    'Value fallback ordering contains a cycle among: %s.',
                    implode(', ', array_keys($remaining)),
                ));
            }

            $result[] = $this->items[$next]['definition'];
            unset($remaining[$next]);
            foreach (array_keys($edges[$next] ?? []) as $to) {
                --$indegree[$to];
            }
        }

        return $this->ordered = $result;
    }

    private function assertKnown(string $owner, string $dependency): void
    {
        if (!isset($this->items[$dependency])) {
            throw new InvalidConfigurationException(sprintf(
                'Value fallback "%s" orders itself relative to unknown fallback "%s".',
                $owner,
                $dependency,
            ));
        }
    }

    /** @param array<string, array<string, true>> $edges @param array<string, int> $indegree */
    private static function edge(array &$edges, array &$indegree, string $from, string $to): void
    {
        if ($from === $to || isset($edges[$from][$to])) {
            return;
        }
        $edges[$from][$to] = true;
        ++$indegree[$to];
    }
}
