<?php

namespace Martis\Fields;

use Martis\Enums\MarkdownPreset;

/**
 * Markdown editor field — WYSIWYG Markdown editing with preview.
 *
 * Contexts:
 *  - index: hidden by default (long text not suitable)
 *  - detail: content hidden behind "Show Content" by default;
 *            alwaysShow() expands automatically
 *  - create: Markdown editor
 *  - update: Markdown editor
 *
 * Stores raw Markdown in the database (not HTML).
 * Rendering to HTML happens on the frontend.
 *
 * Notes:
 *  - withFiles() registers the disk but uploading is managed by the
 *    generic Martis attachments endpoint, without auxiliary tables.
 *  - Presets only control the frontend configuration (rendering);
 *    the backend always stores raw Markdown.
 */
class Markdown extends Field
{
    protected bool $alwaysShow = false;

    protected MarkdownPreset $preset = MarkdownPreset::Default;

    protected ?string $withFilesDisk = null;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'markdown';
    }

    /** {@inheritdoc} */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    /**
     * Always show.
     */
    public function alwaysShow(): static
    {
        $this->alwaysShow = true;

        return $this;
    }

    /**
     * Preset.
     */
    public function preset(MarkdownPreset $preset): static
    {
        $this->preset = $preset;

        return $this;
    }

    /**
     * With files.
     */
    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    /**
     * Is always show.
     */
    public function isAlwaysShow(): bool
    {
        return $this->alwaysShow;
    }

    /**
     * Get preset.
     */
    public function getPreset(): MarkdownPreset
    {
        return $this->preset;
    }

    /**
     * Get with files disk.
     */
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
