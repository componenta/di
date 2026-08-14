<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\ObjectExporter;

/** Adds trusted compiler expressions to the generic var-export object strategy. */
final readonly class DiCacheObjectExporter implements ObjectExporterInterface
{
    private ObjectExporterInterface $fallback;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
    ) {
        $this->fallback = new ObjectExporter($config);
    }

    public function export(object $object): string
    {
        return $this->exportWithDepth($object, 0);
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        if (!$object instanceof GeneratedDefinitionCode) {
            return $this->fallback->exportWithDepth($object, $depth);
        }

        if ($depth === 0 || !str_contains($object->code, "\n")) {
            return $object->code;
        }

        $indent = str_repeat($this->config->indent, $depth);

        return str_replace("\n", "\n" . $indent, $object->code);
    }

    public function supports(object $object): bool
    {
        return $object instanceof GeneratedDefinitionCode
            || $this->fallback->supports($object);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self($config);
    }
}
