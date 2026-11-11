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
use Martis\Enums\InapplicableAction;
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

    /** How the action is drawn for a record it cannot run on. */
    protected InapplicableAction $whenInapplicable = InapplicableAction::Disabled;

    /** An inline action drawn as an item of the row's "..." menu, not as a row button. */
    protected bool $inRowMenu = false;

    // -------------------------------------------------------------------------
    // Execution mode
    // -------------------------------------------------------------------------

    protected ActionExecutionMode $executionMode = ActionExecutionMode::Normal;

    // -------------------------------------------------------------------------
    // Authorization callbacks
    // -------------------------------------------------------------------------

    protected ?Closure $canSeeCallback = null;

    protected ?Closure $canRunCallback = null;

    /**
     * Batched twin of `$canRunCallback`: `(Request, Collection<int, Model>): array<id, bool>`.
     */
    protected ?Closure $canRunForRowsCallback = null;

    // -------------------------------------------------------------------------
    // Confirmation modal
    // -------------------------------------------------------------------------

    /**
     * Whether a confirmation text is a closure, resolved per opening of the
     * modal by the fields endpoint rather than fixed in the schema.
     */
    public function hasDynamicConfirmation(): bool
    {
        return $this->confirmText instanceof Closure
            || $this->confirmButtonText instanceof Closure
            || $this->cancelButtonText instanceof Closure;
    }

    /**
     * The three confirmation texts for one opening of the modal. A closure
     * receives the fields request, the record the modal runs on (the one
     * selectedModel() returns, null for a bulk or standalone run) and the
     * number of selected records (display only: sent by the client, never
     * used to authorize or to run). It returns the text, or null for the
     * default; anything else throws, naming the action and the option.
     *
     * @return array{confirmText: ?string, confirmButtonText: ?string, cancelButtonText: ?string}
     */
    public function resolveConfirmation(Request $request, ?Model $model, int $count): array
    {
        $resolve = function (string $option, string|Closure|null $value) use ($request, $model, $count): ?string {
            if (! $value instanceof Closure) {
                return $value;
            }

            $text = $value($request, $model, $count);

            if ($text !== null && ! is_string($text)) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s() closure of %s must return a string or null, %s returned.',
                    $option,
                    static::class,
                    get_debug_type($text),
                ));
            }

            return $text;
        };

        return [
            'confirmText' => $resolve('confirmText', $this->confirmText),
            'confirmButtonText' => $resolve('confirmButtonText', $this->confirmButtonText),
            'cancelButtonText' => $resolve('cancelButtonText', $this->cancelButtonText),
        ];
    }

    /** @var string|Closure(Request, ?Model, int): ?string|null */
    protected string|Closure|null $confirmText = null;

    /** @var string|Closure(Request, ?Model, int): ?string|null */
    protected string|Closure|null $confirmButtonText = null;

    /** @var string|Closure(Request, ?Model, int): ?string|null */
    protected string|Closure|null $cancelButtonText = null;

    protected bool $withConfirmation = true;

    protected ?ModalSize $modalSize = null;

    /** The run answers `ActionResponse::openInNewTab()`: the panel opens the tab on the click. */
    protected bool $opensInNewTab = false;

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
    // Record context for fields()
    // -------------------------------------------------------------------------

    /** The one record the action's fields are served or validated for; see `selectedModel()`. */
    private ?Model $selectedModel = null;

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

    /**
     * The record the action runs on, for `fields()` to read: set when the
     * modal is opened for one record (the fields request names it with
     * `resourceId`) and when a run names exactly one. It is `null` for a
     * standalone or bulk action, and when the request names no record. The
     * record is the one the run resolves, through the same scope and the same
     * authorization, so an app never reads a record the run would refuse.
     *
     * It is read through this method and not handed to `fields()` as a second
     * parameter, as `ActionContract::fields()` would then change for every
     * Action an application declares. Nova reads it with `$request->findModel()`.
     */
    public function selectedModel(): ?Model
    {
        return $this->selectedModel;
    }

    /**
     * Set the record `selectedModel()` returns, or clear it. Called by the
     * controllers before `fields()`, always (with `null` when there is no
     * record), so an Action instance an application keeps across requests
     * never carries the previous request's record.
     *
     * @internal
     */
    public function withSelectedModel(?Model $model): static
    {
        $this->selectedModel = $model;

        return $this;
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
    public function canRunForRows(Closure $callback): static
    {
        $this->canRunForRowsCallback = $callback;

        return $this;
    }

    /** {@inheritdoc} */
    public function hasBatchedCanRun(): bool
    {
        return $this->canRunForRowsCallback !== null;
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
        // One record asked of a batched rule: the batch decides, so a single
        // row check (the detail page, a pivot run) never skips it.
        if ($this->canRunForRowsCallback !== null) {
            return $this->authorizedToRunRows($request, new \Illuminate\Database\Eloquent\Collection([$model]))[(string) $model->getKey()] ?? false;
        }

        if ($this->canRunCallback !== null) {
            return (bool) ($this->canRunCallback)($request, $model);
        }

        return true;
    }

    /** {@inheritdoc} */
    public function authorizedToRunRows(Request $request, Collection $rows): array
    {
        $answers = [];

        if ($this->canRunForRowsCallback !== null) {
            $returned = ($this->canRunForRowsCallback)($request, $rows);

            if ($returned instanceof Collection) {
                $returned = $returned->all();
            }

            if (! is_array($returned)) {
                throw new \UnexpectedValueException(sprintf(
                    '%s::canRunForRows() must return an array of [row key => bool], %s returned.',
                    static::class,
                    get_debug_type($returned),
                ));
            }

            // Keys compared as strings, so an int key and its string form
            // (or a uuid) always meet.
            foreach ($returned as $key => $answer) {
                $answers[(string) $key] = $answer;
            }
        }

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row->getKey();

            // A row the batch does not answer falls back to the per-row rule,
            // or is denied when there is none: an authorization rule fails closed.
            $map[$key] = array_key_exists($key, $answers)
                ? (bool) $answers[$key]
                : ($this->canRunCallback !== null && (bool) ($this->canRunCallback)($request, $row));
        }

        return $map;
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
    public function confirmText(string|Closure $text): static
    {
        $this->confirmText = $text;

        return $this;
    }

    /** {@inheritdoc} */
    public function confirmButtonText(string|Closure $text): static
    {
        $this->confirmButtonText = $text;

        return $this;
    }

    /** {@inheritdoc} */
    public function cancelButtonText(string|Closure $text): static
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

    /**
     * Declare that the run answers `ActionResponse::openInNewTab()`.
     *
     * The panel then opens a blank tab on the click that runs the action
     * (Run Action, or the action itself when it skips the modal) and sends
     * it to the URL once the answer arrives. A tab opened only after the
     * request returns is no longer tied to a click, so Safari, and the other
     * browsers on a slow run, block it. Any other answer, or an error,
     * closes the blank tab. Not on `ActionContract`, so an application's own
     * implementation of the contract keeps compiling.
     */
    public function opensInNewTab(bool $value = true): static
    {
        $this->opensInNewTab = $value;

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
    /**
     * How the action is drawn for a record it cannot run on (the row's
     * `_actionAuthorization` verdict is false): greyed out (the default),
     * left out (`Hide`), or, for a row button, an invisible placeholder that
     * keeps its slot (`KeepSlot`). Applies to the index rows, the relationship
     * panels and the detail page. The run refuses the record either way.
     */
    public function whenInapplicable(InapplicableAction $mode): static
    {
        $this->whenInapplicable = $mode;

        return $this;
    }

    /** See whenInapplicable(). */
    public function getWhenInapplicable(): InapplicableAction
    {
        return $this->whenInapplicable;
    }

    /**
     * Draw this inline action as a flat item of the row's "..." menu instead
     * of a row button. With a group() it sits in that group's submenu, as any
     * grouped action. Flat items come first, then the group submenus.
     */
    public function inRowMenu(bool $value = true): static
    {
        $this->inRowMenu = $value;

        return $this;
    }

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
            'inRowMenu' => $this->inRowMenu,
            'whenInapplicable' => $this->whenInapplicable->value,
            'executionMode' => $this->executionMode->value,
            'standalone' => $this->isStandalone(),
            'sole' => $this->isSole(),
            'queued' => $this->isQueued(),
            'withConfirmation' => $this->withConfirmation,
            // A closure is resolved per opening by the fields endpoint:
            // the schema carries null and `dynamicConfirm`, so the modal
            // waits for that answer before it shows the text.
            'confirmText' => $this->confirmText instanceof Closure ? null : $this->confirmText,
            'confirmButtonText' => $this->confirmButtonText instanceof Closure ? null : $this->confirmButtonText,
            'cancelButtonText' => $this->cancelButtonText instanceof Closure ? null : $this->cancelButtonText,
            'dynamicConfirm' => $this->hasDynamicConfirmation(),
            'opensInNewTab' => $this->opensInNewTab,
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
