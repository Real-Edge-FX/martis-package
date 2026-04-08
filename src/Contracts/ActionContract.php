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

    /** Set the menu group for this action (dot-notation for submenus). */
    public function group(string $group): static;

    /** Get the group name. */
    public function getGroup(): ?string;

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
