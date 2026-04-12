<?php

namespace Martis\Fields;

use Martis\Enums\MarkdownPreset;

/**
 * Markdown editor field — WYSIWYG Markdown editing with preview.
 *
 * Paridade com Laravel Nova v5: Markdown field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields
 *
 * Contextos:
 *  - index: oculto por padrão (texto longo não adequado)
 *  - detail: conteúdo oculto atrás de "Show Content" por padrão;
 *            alwaysShow() expande automaticamente
 *  - create: editor Markdown
 *  - update: editor Markdown
 *
 * Armazena Markdown bruto no banco (não HTML).
 * Renderização para HTML acontece no frontend.
 *
 * Divergências intencionais do Nova:
 *  - withFiles() registra o disk mas o upload é gerenciado pelo endpoint
 *    genérico de attachments do Martis, sem tabelas auxiliares do Nova.
 *  - Presets controlam apenas a configuração do frontend (renderização);
 *    o backend armazena sempre Markdown bruto.
 */
class Markdown extends Field
{
    protected bool $alwaysShow = false;

    protected MarkdownPreset $preset = MarkdownPreset::Default;

    protected ?string $withFilesDisk = null;

    /** Returns the field type identifier. */
    public function type(): string
    {
        return 'markdown';
    }

    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    /** Always render the Markdown editor (never collapse to preview). */
    public function alwaysShow(): static
    {
        $this->alwaysShow = true;

        return $this;
    }

    /** Set the Markdown rendering preset. */
    public function preset(MarkdownPreset $preset): static
    {
        $this->preset = $preset;

        return $this;
    }

    /** Enable file attachments, optionally on a specific storage disk. */
    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    /** Whether the editor is always rendered in edit mode. */
    public function isAlwaysShow(): bool
    {
        return $this->alwaysShow;
    }

    /** Get the active Markdown rendering preset. */
    public function getPreset(): MarkdownPreset
    {
        return $this->preset;
    }

    /** Get the storage disk used for file attachments. */
    public function getWithFilesDisk(): ?string
    {
        return $this->withFilesDisk;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'alwaysShow' => $this->alwaysShow,
            'preset' => $this->preset->value,
            'withFiles' => $this->withFilesDisk,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
