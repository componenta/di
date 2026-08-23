<?php

declare(strict_types=1);

namespace Componenta\DI\Cache;

use Closure;
use Componenta\DI\Compile\Definition\GeneratedDefinitionCode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ArrayExporterInterface;
use Componenta\VarExport\Contract\ContextualObjectExporterInterface;
use Componenta\VarExport\Contract\ContextualValueExporterInterface;
use Componenta\VarExport\Contract\ObjectExporterInterface;
use Componenta\VarExport\ExportContext;
use Componenta\VarExport\ObjectExporter;
use Throwable;

/**
 * Adds compiler-owned raw expressions to the generic var-export object strategy.
 *
 * @deprecated The default cache pipeline uses DiCacheGraphExporter directly.
 *             Kept for compatibility with callers that compose an object strategy.
 */
final readonly class DiCacheObjectExporter implements ContextualObjectExporterInterface
{
    private ObjectExporterInterface $fallback;

    /** @param array<int, true> $trustedGeneratedCode Object ids emitted by the definition compiler. */
    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        private array $trustedGeneratedCode = [],
        private ?Closure $arrayExporterProvider = null,
        private ?ContextualValueExporterInterface $valueExporter = null,
    ) {
        $fallback = new ObjectExporter(
            $config->withGenericReadonlyObjects(),
            arrayExporterProvider: $arrayExporterProvider,
        );

        $this->fallback = $valueExporter !== null
            ? $fallback->withValueExporter($valueExporter)
            : $fallback;
    }

    public function export(object $object): string
    {
        return $this->exportWithContext($object, ExportContext::root());
    }

    public function exportWithDepth(object $object, int $depth): string
    {
        return $this->exportWithContext(
            $object,
            new ExportContext($depth, baseIndent: str_repeat($this->config->indent, $depth)),
        );
    }

    public function exportWithContext(object $object, ExportContext $context): string
    {
        if ($this->isTrustedGeneratedCode($object)) {
            /** @var GeneratedDefinitionCode $object */
            if ($context->depth === 0 || !str_contains($object->code, "\n")) {
                return $object->code;
            }

            $indent = str_repeat($this->config->indent, $context->depth);

            return str_replace("\n", "\n" . $indent, $object->code);
        }

        if ($this->fallback instanceof ContextualObjectExporterInterface) {
            return $this->fallback->exportWithContext($object, $context);
        }

        return $this->fallback->exportWithDepth($object, $context->depth);
    }

    public function supports(object $object): bool
    {
        try {
            $this->export($object);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self(
            $config,
            $this->trustedGeneratedCode,
            $this->arrayExporterProvider,
        );
    }

    public function withValueExporter(ContextualValueExporterInterface $valueExporter): static
    {
        return new self(
            $this->config,
            $this->trustedGeneratedCode,
            $this->arrayExporterProvider,
            $valueExporter,
        );
    }

    private function isTrustedGeneratedCode(object $object): bool
    {
        return $object instanceof GeneratedDefinitionCode
            && isset($this->trustedGeneratedCode[spl_object_id($object)]);
    }
}
