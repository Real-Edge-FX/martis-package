<?php

namespace Martis\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Enums\ActionExecutionMode;
use Martis\Enums\ModalSize;

/**
 * Contract for all Martis Action classes.
 *
 * This contract must mirror every public method on the base Action class 1:1.
 * Any method added to Action.php MUST be added here as well.
 *
 * Nova v5 parity: covers handle, fields, authorization, visibility,
 * execution mode, modal customization, and action log control.
 */
interface ActionContract
{
    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /** Create a new action instance. */
    public static function make(mixed ...$arguments): static;

    // -------------------------------------------------------------------------
    // Core identity
    // -------------------------------------------------------------------------

    /** Return the display name for this action. */
    public function name(): string;

    /** Return the URI key used to identify this action in routes. */
    public function uriKey(): string;

    /**
     * Execute the action on the given models.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null;

    /**
     * Define the fields for this action's confirmation modal.
     *
     * @return list<FieldContract>
     */
    public function fields(Request $request): array;

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /** Set a callback that determines whether this action is visible. */
    public function canSee(Closure $callback): static;

    /** Set a callback that determines whether this action can run against a model. */
    public function canRun(Closure $callback): static;

    /** Determine if the action is visible to the current request. */
    public function authorizedToSee(Request $request): bool;

    /** Determine if the action can run against a specific model. */
    public function authorizedToRun(Request $request, Model $model): bool;

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    /** Ensure visible on index page. */
    public function showOnIndex(): static;

    /** Ensure visible on detail page. */
    public function showOnDetail(): static;

    /** Display directly on table rows (inline button). */
    public function showInline(): static;

    /** Show ONLY on index. */
    public function onlyOnIndex(): static;

    /** Show ONLY on detail. */
    public function onlyOnDetail(): static;

    /** Show ONLY as inline on table rows. */
    public function onlyInline(): static;

    /** Hide from index. */
    public function exceptOnIndex(): static;

    /** Hide from detail. */
    public function exceptOnDetail(): static;

    /** Hide from inline display. */
    public function exceptInline(): static;

    /** Whether the action is visible on index. */
    public function isShownOnIndex(): bool;

    /** Whether the action is visible on detail. */
    public function isShownOnDetail(): bool;

    /** Whether the action is shown inline. */
    public function isShownInline(): bool;

    // -------------------------------------------------------------------------
    // Execution mode
    // -------------------------------------------------------------------------

    /** Set the action to standalone mode (no resource selection required). */
    public function standalone(): static;

    /** Set the action to sole mode (exactly one resource must be selected). */
    public function sole(): static;

    /** Return the current execution mode. */
    public function executionMode(): ActionExecutionMode;

    /** Whether this action is standalone. */
    public function isStandalone(): bool;

    /** Whether this action is sole. */
    public function isSole(): bool;

    /** Whether this action is destructive. */
    public function isDestructive(): bool;

    // -------------------------------------------------------------------------
    // Confirmation modal
    // -------------------------------------------------------------------------

    /** Set the confirmation dialog text. */
    public function confirmText(string $text): static;

    /** Set the confirm button text. */
    public function confirmButtonText(string $text): static;

    /** Set the cancel button text. */
    public function cancelButtonText(string $text): static;

    /** Skip the confirmation dialog entirely. */
    public function withoutConfirmation(): static;

    /** Set the modal size. */
    public function size(ModalSize $size): static;

    /** Set the modal to fullscreen. */
    public function fullscreen(): static;

    // -------------------------------------------------------------------------
    // Action log control
    // -------------------------------------------------------------------------

    /** Disable action event logging for this action. */
    public function withoutActionEvents(): static;

    /** Whether this action should log events. */
    public function shouldLogEvents(): bool;

    // -------------------------------------------------------------------------
    // Icon & Group
    // -------------------------------------------------------------------------

    /** Set the Phosphor icon name for this action. */
    public function icon(string $icon): static;

    /** Get the icon name. */
    public function getIcon(): ?string;

    /** Hide the icon entirely — the menu item shows label only. */
    public function withoutIcon(): static;

    /** Whether an icon should be rendered for this action. */
    public function isShowingIcon(): bool;

    /** Set a CSS color for the icon (e.g. "#dc2626", "var(--martis-danger)"). */
    public function iconColor(string $color): static;

    /** Get the icon color. */
    public function getIconColor(): ?string;

    /** Set the menu group for this action (dot-notation for submenus). */
    public function group(string $group): static;

    /** Get the group name. */
    public function getGroup(): ?string;

    // -------------------------------------------------------------------------
    // Pivot action support (Nova v5 parity)
    // -------------------------------------------------------------------------

    /**
     * Mark this action as a pivot action (runs on BelongsToMany pivot context).
     * Pivot actions receive related models loaded via the parent relationship,
     * so each model has access to `$model->pivot` with pivot column data.
     */
    public function pivotAction(): static;

    /** Whether this action is a pivot action. */
    public function isPivotAction(): bool;

    /** Set a custom label for the pivot section header. */
    public function referToPivotAs(string $label): static;

    /** Get the pivot section label. */
    public function getPivotLabel(): ?string;

    // -------------------------------------------------------------------------
    // Then callback
    // -------------------------------------------------------------------------

    /** Get the then callback. */
    public function getThenCallback(): ?Closure;

    // -------------------------------------------------------------------------
    // Queued action support
    // -------------------------------------------------------------------------

    /** Whether this action should be queued. */
    public function isQueued(): bool;

    // -------------------------------------------------------------------------
    // Closure handler accessors
    // -------------------------------------------------------------------------

    /** Get the closure handler for closure-based actions. */
    public function getClosureHandler(): ?Closure;

    // -------------------------------------------------------------------------
    // Martis extensions: Dry-run / Preview
    // -------------------------------------------------------------------------

    /** Enable dry-run preview for this action. */
    public function withDryRun(): static;

    /** Whether dry-run preview is enabled. */
    public function hasDryRun(): bool;

    /**
     * Execute a dry-run preview (no side effects).
     *
     * @param  Collection<int, Model>  $models
     * @return array<string, mixed>
     */
    public function dryRun(ActionFields $fields, Collection $models): array;

    // -------------------------------------------------------------------------
    // Martis extensions: Custom component
    // -------------------------------------------------------------------------

    /**
     * Use a custom component inside the action modal.
     *
     * @param  array<string, mixed>  $props
     */
    public function component(string $componentKey, array $props = []): static;

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Serialize the action to JSON for the frontend.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array;
}
