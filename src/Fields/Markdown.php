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

    public function type(): string
    {
        return 'markdown';
    }

    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    public function alwaysShow(): static
    {
        $this->alwaysShow = true;

        return $this;
    }

    public function preset(MarkdownPreset $preset): static
    {
        $this->preset = $preset;

        return $this;
    }

    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    public function isAlwaysShow(): bool
    {
        return $this->alwaysShow;
    }

    public function getPreset(): MarkdownPreset
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
            'preset' => $this->preset->value,
            'withFiles' => $this->withFilesDisk,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
