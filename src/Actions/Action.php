<?php

namespace Martis\Actions;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Martis\Contracts\ActionContract;
use Martis\Enums\ActionExecutionMode;
use Martis\Enums\ModalSize;

/**
 * Base class for all Martis actions.
 *
 * Actions expose handle(), fields(), authorization hooks, visibility controls,
 * modal customization, queued support, and an action log.
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

    /** Whether to show an icon on this action. When false, no icon is rendered. */
    protected bool $showIcon = true;

    /** CSS color for the icon (e.g. "#dc2626", "var(--martis-accent)"). */
    protected ?string $iconColor = null;

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

    /** {@inheritdoc} */
    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    // -------------------------------------------------------------------------
    // Core contract
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function name(): string
    {
        return $this->name ?? Str::headline(class_basename(static::class));
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Action executed successfully.');
    }

    /** {@inheritdoc} */
    public function fields(Request $request): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function canSee(Closure $callback): static
    {
        $this->canSeeCallback = $callback;

        return $this;
    }

    /** {@inheritdoc} */
    public function canRun(Closure $callback): static
    {
        $this->canRunCallback = $callback;

        return $this;
    }

    /** {@inheritdoc} */
    public function authorizedToSee(Request $request): bool
    {
        if ($this->canSeeCallback !== null) {
            return (bool) ($this->canSeeCallback)($request);
        }

        return true;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function showOnIndex(): static
    {
        $this->showOnIndex = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function showOnDetail(): static
    {
        $this->showOnDetail = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function showInline(): static
    {
        $this->showInline = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function onlyOnIndex(): static
    {
        $this->showOnIndex = true;
        $this->showOnDetail = false;
        $this->showInline = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function onlyOnDetail(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = true;
        $this->showInline = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function onlyInline(): static
    {
        $this->showOnIndex = false;
        $this->showOnDetail = false;
        $this->showInline = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function exceptOnIndex(): static
    {
        $this->showOnIndex = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function exceptOnDetail(): static
    {
        $this->showOnDetail = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function exceptInline(): static
    {
        $this->showInline = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function isShownOnIndex(): bool
    {
        return $this->showOnIndex;
    }

    /** {@inheritdoc} */
    public function isShownOnDetail(): bool
    {
        return $this->showOnDetail;
    }

    /** {@inheritdoc} */
    public function isShownInline(): bool
    {
        return $this->showInline;
    }

    // -------------------------------------------------------------------------
    // Execution mode
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function standalone(): static
    {
        $this->executionMode = ActionExecutionMode::Standalone;

        return $this;
    }

    /** {@inheritdoc} */
    public function sole(): static
    {
        $this->executionMode = ActionExecutionMode::Sole;

        return $this;
    }

    /** {@inheritdoc} */
    public function executionMode(): ActionExecutionMode
    {
        return $this->executionMode;
    }

    /** {@inheritdoc} */
    public function isStandalone(): bool
    {
        return $this->executionMode === ActionExecutionMode::Standalone;
    }

    /** {@inheritdoc} */
    public function isSole(): bool
    {
        return $this->executionMode === ActionExecutionMode::Sole;
    }

    /** {@inheritdoc} */
    public function isDestructive(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Confirmation modal
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function confirmText(string $text): static
    {
        $this->confirmText = $text;

        return $this;
    }

    /** {@inheritdoc} */
    public function confirmButtonText(string $text): static
    {
        $this->confirmButtonText = $text;

        return $this;
    }

    /** {@inheritdoc} */
    public function cancelButtonText(string $text): static
    {
        $this->cancelButtonText = $text;

        return $this;
    }

    /** {@inheritdoc} */
    public function withoutConfirmation(): static
    {
        $this->withConfirmation = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function size(ModalSize $size): static
    {
        $this->modalSize = $size;

        return $this;
    }

    /** {@inheritdoc} */
    public function fullscreen(): static
    {
        $this->modalSize = ModalSize::Fullscreen;

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

    /** {@inheritdoc} */
    public function getThenCallback(): ?Closure
    {
        return $this->thenCallback;
    }

    // -------------------------------------------------------------------------
    // Action log control
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function withoutActionEvents(): static
    {
        $this->withoutActionEvents = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function shouldLogEvents(): bool
    {
        return ! $this->withoutActionEvents;
    }

    // -------------------------------------------------------------------------
    // Queued action support
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function isQueued(): bool
    {
        return $this instanceof ShouldQueue;
    }

    // -------------------------------------------------------------------------
    // Closure handler accessors
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function getClosureHandler(): ?Closure
    {
        return $this->closureHandler;
    }

    // -------------------------------------------------------------------------
    // Martis extensions: Dry-run / Preview
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function withDryRun(): static
    {
        $this->supportsDryRun = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function hasDryRun(): bool
    {
        return $this->supportsDryRun;
    }

    /** {@inheritdoc} */
    public function dryRun(ActionFields $fields, Collection $models): array
    {
        return ['preview' => 'No preview available.'];
    }

    // -------------------------------------------------------------------------
    // Martis extensions: Custom component
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function getIcon(): ?string
    {
        return $this->icon;
    }

    /** {@inheritdoc} */
    public function withoutIcon(): static
    {
        $this->showIcon = false;

        return $this;
    }

    /** {@inheritdoc} */
    public function isShowingIcon(): bool
    {
        return $this->showIcon;
    }

    /** Set a CSS color for the icon (e.g. "#dc2626", "var(--martis-danger)"). */
    public function iconColor(string $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    /** {@inheritdoc} */
    public function getIconColor(): ?string
    {
        return $this->iconColor;
    }

    /** {@inheritdoc} */
    public function group(string $group): static
    {
        $this->group = $group;

        return $this;
    }

    /** {@inheritdoc} */
    public function getGroup(): ?string
    {
        return $this->group;
    }

    // -------------------------------------------------------------------------
    // Serialization

    // -------------------------------------------------------------------------
    // Pivot action support
    // -------------------------------------------------------------------------

    /** Whether this action is a pivot action (runs on BelongsToMany pivot table). */
    protected bool $isPivotAction = false;

    /** Custom label for the pivot section. */
    protected ?string $pivotLabel = null;

    /** {@inheritdoc} */
    public function pivotAction(): static
    {
        $this->isPivotAction = true;

        return $this;
    }

    /** {@inheritdoc} */
    public function isPivotAction(): bool
    {
        return $this->isPivotAction;
    }

    /** {@inheritdoc} */
    public function referToPivotAs(string $label): static
    {
        $this->pivotLabel = $label;

        return $this;
    }

    /** {@inheritdoc} */
    public function getPivotLabel(): ?string
    {
        return $this->pivotLabel;
    }

    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function jsonSerialize(): array
    {
        return [
            'uriKey' => $this->uriKey(),
            'name' => $this->name(),
            'icon' => $this->icon,
            'showIcon' => $this->showIcon,
            'iconColor' => $this->iconColor,
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
            'modalSize' => ($this->modalSize ?? ModalSize::Medium)->value,
            'supportsDryRun' => $this->supportsDryRun,
            'customComponent' => $this->customComponent,
            'customComponentProps' => $this->customComponentProps,
            'logEvents' => $this->shouldLogEvents(),
            'isPivotAction' => $this->isPivotAction,
            'pivotLabel' => $this->pivotLabel,
        ];
    }
}
