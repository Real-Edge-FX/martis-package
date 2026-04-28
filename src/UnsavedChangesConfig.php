<?php

namespace Martis;

use Martis\Contracts\UnsavedChangesConfigContract;

/**
 * Fluent configuration builder for the "unsaved changes" confirmation
 * dialog.
 *
 * Usage:
 *
 *     use Martis\UnsavedChangesConfig;
 *
 *     public static function confirmUnsavedChanges(): bool|UnsavedChangesConfigContract
 *     {
 *         return UnsavedChangesConfig::make()
 *             ->title(__('projects.unsaved_title'))
 *             ->body(__('projects.unsaved_body'))
 *             ->icon('question')
 *             ->iconColor('info')
 *             ->confirmLabel(__('projects.discard'))
 *             ->cancelLabel(__('projects.keep'));
 *     }
 *
 * Returning the config is equivalent to returning `true` — it both
 * enables the dialog and overrides the defaults. Return `false` to
 * disable the dialog outright; return `true` for the package defaults.
 */
class UnsavedChangesConfig implements UnsavedChangesConfigContract
{
    protected ?string $title = null;

    protected ?string $body = null;

    protected ?string $icon = null;

    protected ?string $iconColor = null;

    protected ?string $confirmLabel = null;

    protected ?string $confirmColor = null;

    protected ?string $cancelLabel = null;

    public static function make(): self
    {
        return new self;
    }

    /** Custom dialog title (defaults to the localised "Unsaved changes"). */
    public function title(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /** Custom body text (defaults to the localised prompt). */
    public function body(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Name of the Phosphor icon to display at the top-left of the dialog.
     * Pass `null` to hide the icon entirely (use `icon('')` or skip to keep
     * the default warning glyph).
     */
    public function icon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Colour for the icon. Accepts semantic tokens
     * (`success | warning | danger | info | muted | accent`), CSS variables
     * (`var(--custom)`) or arbitrary CSS colours (hex, rgb, named).
     */
    public function iconColor(string $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    /** Label for the destructive/confirm button (defaults to "Discard"). */
    public function confirmLabel(string $label): static
    {
        $this->confirmLabel = $label;

        return $this;
    }

    /**
     * Colour for the confirm button background. Same accepted formats as
     * {@see iconColor()}. Defaults to `danger`.
     */
    public function confirmColor(string $color): static
    {
        $this->confirmColor = $color;

        return $this;
    }

    /** Label for the cancel/keep-editing button. */
    public function cancelLabel(string $label): static
    {
        $this->cancelLabel = $label;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Only non-null values are serialised so the frontend knows which keys
     * to merge over its own defaults.
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'body' => $this->body,
            'icon' => $this->icon,
            'iconColor' => $this->iconColor,
            'confirmLabel' => $this->confirmLabel,
            'confirmColor' => $this->confirmColor,
            'cancelLabel' => $this->cancelLabel,
        ], static fn ($v) => $v !== null);
    }
}
