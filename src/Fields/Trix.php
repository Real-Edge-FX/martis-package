<?php

namespace Martis\Fields;

use Martis\Enums\ClickBehavior;
use Martis\Enums\ToolbarSize;

/**
 * Trix rich-text editor field — HTML-based WYSIWYG editing.
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
 * Notes:
 *  - withFiles() lets the editor attach files: the upload goes to the
 *    generic Martis attachments endpoint, which authorises it like the form
 *    the field is on and stores the file on the field's disk, without
 *    auxiliary tables.
 */
class Trix extends Field
{
    protected bool $alwaysShow = false;

    /** Whether the editor accepts file attachments (see `withFiles()`). */
    protected bool $withFiles = false;

    /** The disk of the attachments the field declares; null is the panel's `martis.storage.disk`. */
    protected ?string $withFilesDisk = null;

    protected ?ToolbarSize $toolbarSize = null;

    protected ClickBehavior $imageClickBehavior = ClickBehavior::Modal;

    protected ClickBehavior $linkClickBehavior = ClickBehavior::SamePage;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'trix';
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
            'withFiles' => $this->getWithFilesDisk(),
            'toolbarSize' => $this->toolbarSize?->value,
            'imageClickBehavior' => $this->imageClickBehavior->value,
            'linkClickBehavior' => $this->linkClickBehavior->value,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
