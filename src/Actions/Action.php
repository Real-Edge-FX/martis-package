<?php

namespace Martis\Actions;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Enums\ActionExecutionMode;
use Martis\Enums\ModalSize;

/**
 * Base class for all Martis actions.
 *
 * Nova v5 parity: Action with handle(), fields(), authorization hooks,
 * visibility controls, modal customization, queued support, and action log.
 *
 * @phpstan-consistent-constructor
 */
class Action implements ActionContract
{
    /** Display name override. */
    public ?string $name = null;

    /** Disable action event logging on this action. */

    /** Phosphor icon name (e.g. "trash", "envelope", "arrow-right"). */
    protected ?string $icon = null;

    /** Action group for menu organisation. Supports dot-notation for submenus (e.g. "Export.CSV"). */
    protected ?string $group = null;

    public bool $withoutActionEvents = false;

    // -------------------------------------------------------------------------
    // Visibility flags
    // -------------------------------------------------------------------------

    protected bool $showOnIndex = true;

    protected bool $showOnDetail = true;

    protected bool $showInline = false;

    // -------------------------------------------------------------------------
    // Execution mode
    // -------------------------------------------------------------------------

    protected ActionExecutionMode $executionMode = ActionExecutionMode::Normal;

    // -------------------------------------------------------------------------
    // Authorization callbacks
    // -------------------------------------------------------------------------

    protected ?Closure $canSeeCallback = null;

    protected ?Closure $canRunCallback = null;

    // -------------------------------------------------------------------------
    // Confirmation modal
    // -------------------------------------------------------------------------

    protected ?string $confirmText = null;

    protected ?string $confirmButtonText = null;

    protected ?string $cancelButtonText = null;

    protected bool $withConfirmation = true;

    protected ?ModalSize $modalSize = null;

    protected bool $fullscreen = false;

    // -------------------------------------------------------------------------
    // Then callback (non-queued actions)
    // -------------------------------------------------------------------------

    protected ?Closure $thenCallback = null;

    // -------------------------------------------------------------------------
    // Dry-run / Preview (Martis extension)
    // -------------------------------------------------------------------------

    protected bool $supportsDryRun = false;

    // -------------------------------------------------------------------------
    // Custom component (Martis extension)
    // -------------------------------------------------------------------------

    protected ?string $customComponent = null;

    /** @var array<string, mixed> */
    protected array $customComponentProps = [];

    // -------------------------------------------------------------------------
    // Closure handler (for Action::using())
    // -------------------------------------------------------------------------

    protected ?Closure $closureHandler = null;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    // -------------------------------------------------------------------------
    // Core contract
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function name(): string
    {
        return $this->name ?? Str::headline(class_basename(static::class));
    }

    /** {@inheritDoc} */
    public function uriKey(): string
    {
        // For closure-based actions (Action::using()), derive uriKey from the name
        // to avoid all closure actions sharing the generic "action" key.
        // Named subclasses keep their class-based uriKey for backwards compatibility.
        if ($this->closureHandler !== null && $this->name !== null) {
            return Str::kebab($this->name);
        }

        return Str::kebab(class_basename(static::class));
    }

    /**
     * Execute the action on the given models.
     *
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Action executed successfully.');
    }

    /**
     * Define the fields for this action's confirmation modal.
     *
     * @return list<FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /** {@inheritDoc} */
    public function canRun(Closure $callback): static
    {
        $this->canRunCallback = $callback;

        return $this;
    }

    /** {@inheritDoc} */
    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback !== null) {
            return (bool) ($this->canSeeCallback)($request);
        }

        return true;
    }

    /** {@inheritDoc} */
    public function authorizedToRun(Request $request, Model $model): bool
    {
        if ($this->canRunCallback !== null) {
            return (bool) ($this->canRunCallback)($request, $model);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function showOnIndex(): static
    {
        $this->showOnIndex = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function showOnDetail(): static
    {
        $this->showOnDetail = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function showInline(): static
    {
        $this->showInline = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function onlyOnIndex(): static
    {
        $this->showOnIndex = true;
        $this->showOnDetail = false;
        $this->showInline = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function onlyOnDetail(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = true;
        $this->showInline = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function onlyInline(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = false;
        $this->showInline = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function exceptOnIndex(): static
    {
        $this->showOnIndex = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function exceptOnDetail(): static
    {
        $this->showOnDetail = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function exceptInline(): static
    {
        $this->showInline = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function isShownOnIndex(): bool
    {
        return $this->showOnIndex;
    }

    /** {@inheritDoc} */
    public function isShownOnDetail(): bool
    {
        return $this->showOnDetail;
    }

    /** {@inheritDoc} */
    public function isShownInline(): bool
    {
        return $this->showInline;
    }

    // -------------------------------------------------------------------------
    // Execution mode
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function standalone(): static
    {
        $this->executionMode = ActionExecutionMode::Standalone;

        return $this;
    }

    /** {@inheritDoc} */
    public function sole(): static
    {
        $this->executionMode = ActionExecutionMode::Sole;

        return $this;
    }

    /** {@inheritDoc} */
    public function executionMode(): ActionExecutionMode
    {
        return $this->executionMode;
    }

    /** {@inheritDoc} */
    public function isStandalone(): bool
    {
        return $this->executionMode === ActionExecutionMode::Standalone;
    }

    /** {@inheritDoc} */
    public function isSole(): bool
    {
        return $this->executionMode === ActionExecutionMode::Sole;
    }

    /** {@inheritDoc} */
    public function isDestructive(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Confirmation modal
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function confirmText(string $text): static
    {
        $this->confirmText = $text;

        return $this;
    }

    /** {@inheritDoc} */
    public function confirmButtonText(string $text): static
    {
        $this->confirmButtonText = $text;

        return $this;
    }

    /** {@inheritDoc} */
    public function cancelButtonText(string $text): static
    {
        $this->cancelButtonText = $text;

        return $this;
    }

    /** {@inheritDoc} */
    public function withoutConfirmation(): static
    {
        $this->withConfirmation = false;

        return $this;
    }

    /** {@inheritDoc} */
    public function size(ModalSize $size): static
    {
        $this->modalSize = $size;

        return $this;
    }

    /** {@inheritDoc} */
    public function fullscreen(): static
    {
        $this->fullscreen = true;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Then callback
    // -------------------------------------------------------------------------

    /** Register a callback to run after all models are processed. */
    public function then(Closure $callback): static
    {
        $this->thenCallback = $callback;

        return $this;
    }

    /** Get the then callback. */
    public function getThenCallback(): ?Closure
    {
        return $this->thenCallback;
    }

    // -------------------------------------------------------------------------
    // Action log control
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function withoutActionEvents(): static
    {
        $this->withoutActionEvents = true;

        return $this;
    }

    /** {@inheritDoc} */
    public function shouldLogEvents(): bool
    {
        return ! $this->withoutActionEvents;
    }

    // -------------------------------------------------------------------------
    // Queued action support
    // -------------------------------------------------------------------------

    /** Whether this action should be queued. */
    public function isQueued(): bool
    {
        return $this instanceof ShouldQueue;
    }

    // -------------------------------------------------------------------------
    // Closure handler accessors
    // -------------------------------------------------------------------------

    /** Get the closure handler for closure-based actions. */
    public function getClosureHandler(): ?Closure
    {
        return $this->closureHandler;
    }

    // -------------------------------------------------------------------------
    // Martis extensions: Dry-run / Preview
    // -------------------------------------------------------------------------

    /** Enable dry-run preview for this action. */
    public function withDryRun(): static
    {
        $this->supportsDryRun = true;

        return $this;
    }

    /** Whether dry-run preview is enabled. */
    public function hasDryRun(): bool
    {
        return $this->supportsDryRun;
    }

    /**
     * Execute a dry-run preview (no side effects).
     *
     * @param  Collection<int, Model>  $models
     * @return array<string, mixed>
     */
    public function dryRun(ActionFields $fields, Collection $models): array
    {
        return ['preview' => 'No preview available.'];
    }

    // -------------------------------------------------------------------------
    // Martis extensions: Custom component
    // -------------------------------------------------------------------------

    /**
     * Use a custom component inside the action modal.
     *
     * @param  array<string, mixed>  $props
     */
    public function component(string $componentKey, array $props = []): static
    {
        $this->customComponent = $componentKey;
        $this->customComponentProps = $props;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Static shorthand factories
    // -------------------------------------------------------------------------

    /** Create a closure-based action (not queueable). */
    public static function using(string $name, Closure $callback): static
    {
        $action = new static;
        $action->name = $name;
        $action->closureHandler = $callback;

        return $action;
    }

    // -------------------------------------------------------------------------
    // Icon & Group
    // -------------------------------------------------------------------------

    /** Set the Phosphor icon name for this action (e.g. "trash", "envelope"). */
    public function icon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** Get the icon name. */
    public function getIcon(): ?string
    {
        return $this->icon;
    }

    /**
     * Set the menu group for this action.
     * Use dot-notation for submenus: "Export.CSV", "Notifications.Email".
     */
    public function group(string $group): static
    {
        $this->group = $group;

        return $this;
    }

    /** Get the group name. */
    public function getGroup(): ?string
    {
        return $this->group;
    }

    // -------------------------------------------------------------------------
    // Serialization

    // -------------------------------------------------------------------------
    // Pivot action support (Nova v5 parity)
    // -------------------------------------------------------------------------

    /** Whether this action is a pivot action (runs on BelongsToMany pivot table). */
    protected bool $isPivotAction = false;

    /** Custom label for the pivot section. */
    protected ?string $pivotLabel = null;

    /** Mark this action as a pivot action. */
    public function pivotAction(): static
    {
        $this->isPivotAction = true;

        return $this;
    }

    /** Whether this action is a pivot action. */
    public function isPivotAction(): bool
    {
        return $this->isPivotAction;
    }

    /** Set a custom label for the pivot section. */
    public function referToPivotAs(string $label): static
    {
        $this->pivotLabel = $label;

        return $this;
    }

    /** Get the pivot section label. */
    public function getPivotLabel(): ?string
    {
        return $this->pivotLabel;
    }

    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function jsonSerialize(): array
    {
        return [
            'uriKey' => $this->uriKey(),
            'name' => $this->name(),
            'icon' => $this->icon,
            'group' => $this->group,
            'destructive' => $this->isDestructive(),
            'showOnIndex' => $this->showOnIndex,
            'showOnDetail' => $this->showOnDetail,
            'showInline' => $this->showInline,
            'executionMode' => $this->executionMode->value,
            'standalone' => $this->isStandalone(),
            'sole' => $this->isSole(),
            'queued' => $this->isQueued(),
            'withConfirmation' => $this->withConfirmation,
            'confirmText' => $this->confirmText,
            'confirmButtonText' => $this->confirmButtonText,
            'cancelButtonText' => $this->cancelButtonText,
            'modalSize' => $this->fullscreen ? 'fullscreen' : (($this->modalSize !== null ? $this->modalSize->value : 'md')),
            'supportsDryRun' => $this->supportsDryRun,
            'customComponent' => $this->customComponent,
            'customComponentProps' => $this->customComponentProps,
            'logEvents' => $this->shouldLogEvents(),
            'isPivotAction' => $this->isPivotAction,
            'pivotLabel' => $this->pivotLabel,
        ];
    }
}
