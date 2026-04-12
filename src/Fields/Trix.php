<?php

namespace Martis\Fields;

use Martis\Enums\ClickBehavior;
use Martis\Enums\ToolbarSize;

/**
 * Trix rich-text editor field — HTML-based WYSIWYG editing.
 *
 * Laravel Nova v5 parity: Trix field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields
 *
 * Contexts:
 *  - index: hidden by default (long HTML not suitable)
 *  - detail: content hidden behind "Show Content" by default;
 *            alwaysShow() expands automatically
 *  - create: Trix editor (rich text HTML)
 *  - update: Trix editor (rich text HTML)
 *
 * Stores raw HTML in the database.
 *
 * Intentional divergences from Nova:
 *  - withFiles() registers the disk but uploading is managed by the
 *    generic Martis attachments endpoint, without Nova's auxiliary tables.
 */
class Trix extends Field
{
    protected bool $alwaysShow = false;

    protected ?string $withFilesDisk = null;

    protected ?ToolbarSize $toolbarSize = null;

    protected ClickBehavior $imageClickBehavior = ClickBehavior::Modal;

    protected ClickBehavior $linkClickBehavior = ClickBehavior::SamePage;

    /**
     * Type.
     */
    public function type(): string
    {
        return 'trix';
    }

    /**
     * Make.
     */
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
     * With files.
     */
    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    /**
     * Toolbar size.
     */
    public function toolbarSize(ToolbarSize $size): static
    {
        $this->toolbarSize = $size;

        return $this;
    }

    /**
     * Image click behavior.
     */
    public function imageClickBehavior(ClickBehavior $behavior): static
    {
        $this->imageClickBehavior = $behavior;

        return $this;
    }

    /**
     * Link click behavior.
     */
    public function linkClickBehavior(ClickBehavior $behavior): static
    {
        $this->linkClickBehavior = $behavior;

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
            'withFiles' => $this->withFilesDisk,
            'toolbarSize' => $this->toolbarSize?->value,
            'imageClickBehavior' => $this->imageClickBehavior->value,
            'linkClickBehavior' => $this->linkClickBehavior->value,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
