<?php

namespace Martis\Fields;

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

    protected string $preset = 'default';

    protected ?string $withFilesDisk = null;

    public function type(): string
    {
        return 'markdown';
    }

    /**
     * Override make() to default to hidden on index.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    /**
     * Always show the rendered Markdown content on detail view,
     * instead of hiding it behind a "Show Content" toggle.
     */
    public function alwaysShow(): static
    {
        $this->alwaysShow = true;

        return $this;
    }

    /**
     * Set the Markdown rendering preset.
     *
     * Built-in presets: 'default' (GFM), 'commonmark', 'zero'.
     */
    public function preset(string $preset): static
    {
        $this->preset = $preset;

        return $this;
    }

    /**
     * Enable file uploads in the Markdown editor.
     *
     * @param  string  $disk  The filesystem disk to store uploads on.
     */
    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    public function isAlwaysShow(): bool
    {
        return $this->alwaysShow;
    }

    public function getPreset(): string
    {
        return $this->preset;
    }

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
            'preset' => $this->preset,
            'withFiles' => $this->withFilesDisk,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
