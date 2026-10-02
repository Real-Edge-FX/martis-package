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
 *  - withFiles() lets the editor attach files: the upload goes to the
 *    generic Martis attachments endpoint, which authorises it like the form
 *    the field is on and stores the file on the field's disk, without
 *    auxiliary tables.
 *  - Presets only control the frontend configuration (rendering);
 *    the backend always stores raw Markdown.
 */
class Markdown extends Field
{
    protected bool $alwaysShow = false;

    protected MarkdownPreset $preset = MarkdownPreset::Default;

    /** Whether the editor accepts file attachments (see `withFiles()`). */
    protected bool $withFiles = false;

    /** The disk of the attachments the field declares; null is the panel's `martis.storage.disk`. */
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
     * Let the editor attach files, stored on `$disk`, or on the panel's
     * `martis.storage.disk` (`public` by default) without one.
     *
     * The upload endpoint takes the disk from the field, never from the
     * request, and serves only a field that declares `withFiles()` on the
     * form it is uploaded from.
     */
    public function withFiles(?string $disk = null): static
    {
        $this->withFiles = true;
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
     * The disk the attachments are stored on, or null when the field does not
     * accept files.
     */
    public function getWithFilesDisk(): ?string
    {
        if (! $this->withFiles) {
            return null;
        }

        return $this->withFilesDisk ?? $this->defaultWithFilesDisk();
    }

    /** The panel's storage disk, `public` outside an application (unit tests). */
    private function defaultWithFilesDisk(): string
    {
        $disk = function_exists('app') && app()->bound('config') ? config('martis.storage.disk', 'public') : 'public';

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'alwaysShow' => $this->alwaysShow,
            'preset' => $this->preset->value,
            'withFiles' => $this->getWithFilesDisk(),
        ], fn ($v) => $v !== null && $v !== false);
    }
}
