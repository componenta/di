<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\ObjectExporter;

/** Adds compiler-owned expressions to the generic var-export object strategy. */
final readonly class DiCacheObjectExporter implements ObjectExporterInterface
{
    private ObjectExporterInterface $fallback;

    /**
     * @param array<int, true> $trustedGeneratedCode Object ids emitted by the definition compiler.
     */
    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        private array $trustedGeneratedCode = [],
    ) {
        $this->fallback = new ObjectExporter($config);
    }

    public function export(object $object): string
    {
        return $this->exportWithDepth($object, 0);
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        if (!$this->isTrustedGeneratedCode($object)) {
            return $this->fallback->exportWithDepth($object, $depth);
        }

        /** @var GeneratedDefinitionCode $object */
        if ($depth === 0 || !str_contains($object->code, "\n")) {
            return $object->code;
        }

        $indent = str_repeat($this->config->indent, $depth);

        return str_replace("\n", "\n" . $indent, $object->code);
    }

    public function supports(object $object): bool
    {
        return $this->isTrustedGeneratedCode($object)
            || $this->fallback->supports($object);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self($config, $this->trustedGeneratedCode);
    }

    private function isTrustedGeneratedCode(object $object): bool
    {
        return $object instanceof GeneratedDefinitionCode
            && isset($this->trustedGeneratedCode[spl_object_id($object)]);
    }
}
