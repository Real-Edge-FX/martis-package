<?php

namespace Martis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Martis\Contracts\FieldContract;
use Martis\Contracts\ResourceContract;
use Martis\Enums\ErrorDisplayMode;
use Martis\Enums\TableSize;
use Martis\Events\AfterDelete;
use Martis\Events\AfterSave;
use Martis\Events\BeforeDelete;
use Martis\Events\BeforeSave;

/**
 * Base class for all Martis admin resources.
 *
 * A Resource wraps an Eloquent model and declares its fields, authorization
 * rules, display metadata, and contextual field visibility. Every custom
 * resource in the consuming application MUST extend this class.
 *
 * Design mandate: the backend never renders UI — it only exposes data and
 * metadata via the JSON API. The React frontend is responsible for rendering.
 */
abstract class Resource implements ResourceContract
{
    /**
     * The underlying model instance (null when creating a new record).
     */
    protected ?Model $model;

    public function __construct(?Model $model = null)
    {
        $this->model = $model;
    }

    // -------------------------------------------------------------------------
    // Abstract — must be implemented by concrete resources
    // -------------------------------------------------------------------------

    /**
     * Return all fields for this resource.
     *
     * @return list<FieldContract>
     */
    abstract public function fields(Request $request): array;

    /**
     * Return the fully-qualified Eloquent model class name.
     */
    abstract public static function model(): string;

    // -------------------------------------------------------------------------
    // Defaults — may be overridden by concrete resources
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public static function newModel(): Model
    {
        $class = static::model();

        /** @var Model $instance */
        $instance = new $class;

        return $instance;
    }

    /** {@inheritDoc} */
    public static function uriKey(): string
    {
        return Str::plural(Str::kebab(class_basename(static::model())));
    }

    /** {@inheritDoc} */
    public static function label(): string
    {
        return Str::plural(Str::headline(class_basename(static::model())));
    }

    /** {@inheritDoc} */
    public static function singularLabel(): string
    {
        return Str::headline(class_basename(static::model()));
    }

    /** {@inheritDoc} */
    public static function subtitle(): ?string
    {
        return null;
    }

    /** {@inheritDoc} */
    public static function titleAttribute(): string
    {
        return 'id';
    }

    /**
     * Return the display title for this specific resource instance.
     * Uses the attribute defined by titleAttribute().
     *
     * When titleAttribute is 'id' (default), returns "{SingularLabel} #{id}"
     * so the frontend displays a meaningful label instead of just a number.
     */
    /** {@inheritDoc} */
    public static function indexSearchable(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public static function perPageOptions(): array
    {
        return [10, 25, 50, 100];
    }

    /** {@inheritDoc} */
    public static function perPage(): int
    {
        return 25;
    }

    /** {@inheritDoc} */
    public static function searchPlaceholder(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Query hooks — Nova v5 parity (REA-1144)
    // -------------------------------------------------------------------------

    /**
     * Build a query for the resource index listing.
     *
     * Override this method to add tenant scoping, ownership filtering, or any
     * other structural query constraint for the index (list) view. This is
     * applied server-side before pagination and does NOT replace policies.
     *
     * Nova v5 parity: indexQuery(NovaRequest, Builder): Builder
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }

    /**
     * Build a query for relatable resource options.
     *
     * Override this method to filter which records appear in relationship
     * selectors (BelongsTo dropdowns, Tag pickers, etc.). This is the
     * generic fallback — for per-relationship customization, define
     * relatable{PluralModelName}() instead.
     *
     * Nova v5 parity: relatableQuery(NovaRequest, Builder): Builder
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }

    public function title(): string
    {
        if ($this->model === null) {
            return '';
        }

        $attr = static::titleAttribute();
        $value = (string) $this->model->getAttribute($attr);

        // Default titleAttribute is 'id' — return a formatted label
        if ($attr === 'id') {
            return static::singularLabel().' #'.$value;
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Context-aware field resolution
    //
    // Each method is an explicit override point. Override any of them in your
    // concrete resource to return a different set of fields for that context.
    // If not overridden, every context falls back to fields().
    //
    // Resolution order per context:
    //   index         -> fieldsForIndex()        -> fields()
    //   detail        -> fieldsForDetail()       -> fields()
    //   create        -> fieldsForCreate()       -> fields()
    //   update        -> fieldsForUpdate()       -> fields()
    //   inline-create -> fieldsForInlineCreate() -> fieldsForCreate() -> fields()
    //   preview       -> fieldsForPreview()      -> fields()
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function fieldsForIndex(Request $request): array
    {
        return $this->fields($request);
    }

    /** {@inheritDoc} */
    public function fieldsForDetail(Request $request): array
    {
        return $this->fields($request);
    }

    /** {@inheritDoc} */
    public function fieldsForCreate(Request $request): array
    {
        return $this->fields($request);
    }

    /** {@inheritDoc} */
    public function fieldsForUpdate(Request $request): array
    {
        return $this->fields($request);
    }

    /**
     * Return fields for inline-create context (creating a related record
     * without leaving the current page).
     *
     * Falls back to fieldsForCreate(), which itself falls back to fields().
     *
     * {@inheritDoc}
     */
    public function fieldsForInlineCreate(Request $request): array
    {
        return $this->fieldsForCreate($request);
    }

    /** {@inheritDoc} */
    public function fieldsForPreview(Request $request): array
    {
        return $this->fields($request);
    }

    // -------------------------------------------------------------------------
    // Soft delete awareness
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public static function softDeletes(): bool
    {
        return in_array(
            SoftDeletes::class,
            class_uses_recursive(static::model()),
            true
        );
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /**
     * Determine whether the current user may view any resource of this type.
     */
    public function authorizedToViewAny(Request $request): bool
    {
        return $this->checkPolicy('viewAny', null);
    }

    /**
     * Determine whether the current user may view this specific resource.
     */
    public function authorizedToView(Request $request): bool
    {
        return $this->checkPolicy('view', $this->model);
    }

    /**
     * Determine whether the current user may create resources of this type.
     */
    public function authorizedToCreate(Request $request): bool
    {
        return $this->checkPolicy('create', null);
    }

    /**
     * Determine whether the current user may update this resource.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return $this->checkPolicy('update', $this->model);
    }

    /**
     * Determine whether the current user may delete this resource.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return $this->checkPolicy('delete', $this->model);
    }

    // -------------------------------------------------------------------------
    // Relational authorization — Nova v5 parity (REA-1144)
    //
    // These methods check Model Policy abilities for relationship operations.
    // They coexist with relatableQuery / relatable{PluralModelName} hooks:
    //   - Policy methods = authorization (can the user do this action?)
    //   - Query hooks = structural filtering (which options are available?)
    // One does NOT replace the other.
    // -------------------------------------------------------------------------

    /**
     * Determine whether the user may attach ANY record of the given model type.
     *
     * Nova v5 parity: Policy method `attachAny{Model}`.
     * If no policy is registered, returns true (permissive default).
     */
    public function authorizedToAttachAny(Request $request, string $relatedModelClass): bool
    {
        $ability = 'attachAny'.class_basename($relatedModelClass);

        return $this->checkRelationalPolicy($ability, $relatedModelClass);
    }

    /**
     * Determine whether the user may attach a specific related model.
     *
     * Nova v5 parity: Policy method `attach{Model}`.
     */
    public function authorizedToAttach(Request $request, Model $relatedModel): bool
    {
        $ability = 'attach'.class_basename($relatedModel);

        return $this->checkRelationalPolicy($ability, get_class($relatedModel), $relatedModel);
    }

    /**
     * Determine whether the user may detach a specific related model.
     *
     * Nova v5 parity: Policy method `detach{Model}`.
     */
    public function authorizedToDetach(Request $request, Model $relatedModel): bool
    {
        $ability = 'detach'.class_basename($relatedModel);

        return $this->checkRelationalPolicy($ability, get_class($relatedModel), $relatedModel);
    }

    /**
     * Determine whether the user may add a new record of the given model type
     * (inline creation from a relationship field).
     *
     * Nova v5 parity: Policy method `add{Model}`.
     */
    public function authorizedToAdd(Request $request, string $relatedModelClass): bool
    {
        $ability = 'add'.class_basename($relatedModelClass);

        return $this->checkRelationalPolicy($ability, $relatedModelClass);
    }

    /**
     * Check a relational policy ability.
     *
     * When no policy exists for the source model, or the policy does not define
     * the method, returns true (permissive default).
     *
     * @param  string  $ability  e.g. "attachTag", "detachUser", "addComment"
     * @param  class-string<Model>  $relatedModelClass
     * @param  Model|null  $relatedModel  Specific instance for attach/detach checks
     */
    protected function checkRelationalPolicy(
        string $ability,
        string $relatedModelClass,
        ?Model $relatedModel = null,
    ): bool {
        $modelClass = static::model();

        $policy = Gate::getPolicyFor($modelClass);
        if ($policy === null) {
            return true;
        }

        // If the policy does not define this specific method, allow by default
        if (! method_exists($policy, $ability)) {
            return true;
        }

        if ($this->model !== null && $relatedModel !== null) {
            return Gate::allows($ability, [$this->model, $relatedModel]);
        }

        if ($this->model !== null) {
            return Gate::allows($ability, [$this->model, $relatedModelClass]);
        }

        return Gate::allows($ability, $modelClass);
    }

    /**
     * Check a policy ability for the resource's model.
     *
     * When no policy is registered for the model, returns true (permissive
     * default) so resources work out-of-the-box without requiring policies.
     * Once a policy is registered, it is the sole source of truth.
     *
     * @param  string  $ability  Policy method name (viewAny, view, create, update, delete)
     * @param  Model|null  $model  The model instance (null for collection-level checks)
     */
    protected function checkPolicy(string $ability, ?Model $model): bool
    {
        $modelClass = static::model();

        if (Gate::getPolicyFor($modelClass) === null) {
            return true;
        }

        if ($model !== null) {
            return Gate::allows($ability, $model);
        }

        return Gate::allows($ability, $modelClass);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * Return the navigation group for this resource (null = top-level).
     * Override to group resources in the sidebar.
     */
    /** {@inheritDoc} */
    public function icon(): string
    {
        return 'database';
    }

    // -------------------------------------------------------------------------
    // DataTable display configuration — configurable per resource (REA-1140)
    // -------------------------------------------------------------------------

    /**
     * Whether the DataTable should display striped rows.
     * Override in concrete resources to disable.
     */
    public static function tableStriped(): bool
    {
        return true;
    }

    /**
     * Whether to show vertical grid lines between columns.
     * Override to return true if you want cell borders.
     */
    public static function tableShowGridlines(): bool
    {
        return false;
    }

    /**
     * DataTable size: 'normal', 'small', or 'large'.
     * Controls cell padding and font sizes.
     */
    public static function tableSize(): TableSize
    {
        return TableSize::Normal;
    }

    /**
     * Whether table rows should highlight on hover.
     * Default true — set false for static tables.
     */
    public static function tableRowHover(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function group(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Notification messages — customizable per resource (Nova parity)
    // -------------------------------------------------------------------------

    /**
     * Message shown after a record is created.
     *
     * Override in concrete resources to customize:
     *   public static function createdMessage(): string
     *   {
     *       return 'Novo usuário cadastrado!';
     *   }
     */
    public static function createdMessage(): string
    {
        $msg = __('martis::messages.record_created');

        return is_string($msg) ? $msg : 'Record created successfully.';
    }

    /**
     * Message shown after a record is updated.
     */
    public static function updatedMessage(): string
    {
        $msg = __('martis::messages.record_updated');

        return is_string($msg) ? $msg : 'Record updated successfully.';
    }

    /**
     * Message shown after a record is deleted.
     */
    public static function deletedMessage(): string
    {
        $msg = __('martis::messages.record_deleted');

        return is_string($msg) ? $msg : 'Record deleted successfully.';
    }

    /**
     * Message shown after a soft-deleted record is restored.
     */
    public static function restoredMessage(): string
    {
        $msg = __('martis::messages.record_restored');

        return is_string($msg) ? $msg : 'Record restored successfully.';
    }

    /**
     * Confirmation message shown before deleting a record.
     */
    public static function deleteConfirmMessage(): string
    {
        $msg = __('martis::messages.delete_confirm');

        return is_string($msg) ? $msg : 'This action is permanent and cannot be undone. Are you sure?';
    }

    /**
     * Confirmation message shown before archiving (soft-deleting) a record.
     */
    public static function archiveConfirmMessage(): string
    {
        $msg = __('martis::messages.archive_confirm');

        return is_string($msg) ? $msg : 'This record will be archived. You can restore it later.';
    }

    /** {@inheritDoc} */
    public function toArray(): array
    {
        return [
            'uriKey' => static::uriKey(),
            'label' => static::label(),
            'singularLabel' => static::singularLabel(),
            'subtitle' => static::subtitle(),
            'titleAttribute' => static::titleAttribute(),
            'softDeletes' => static::softDeletes(),
            'group' => $this->group(),
            'icon' => $this->icon(),
        ];
    }

    // -------------------------------------------------------------------------
    // Server-side hooks — Bloco 9
    // -------------------------------------------------------------------------

    /**
     * Error display strategy for this resource.
     *
     * Return "inline" to show validation errors next to each field,
     * or "toast" to show them as toast notifications.
     */
    public static function errorDisplay(): ErrorDisplayMode
    {
        return ErrorDisplayMode::Inline;
    }

    /**
     * Message shown in the toast when validation fails.
     * Override to customize per resource.
     */
    public static function validationMessage(): string
    {
        $msg = __('martis::messages.validation_failed');

        return is_string($msg) ? $msg : 'The given data was invalid.';
    }

    /** {@inheritDoc} */
    public function beforeSave(Model $model, Request $request, bool $creating): void
    {
        BeforeSave::dispatch(static::class, $model, $request, $creating);
    }

    /** {@inheritDoc} */
    public function afterSave(Model $model, Request $request, bool $creating): void
    {
        AfterSave::dispatch(static::class, $model, $request, $creating);
    }

    /** {@inheritDoc} */
    public function beforeDelete(Model $model, Request $request): void
    {
        BeforeDelete::dispatch(static::class, $model, $request);
    }

    /** {@inheritDoc} */
    public function afterDelete(Model $model, Request $request): void
    {
        AfterDelete::dispatch(static::class, $model, $request);
    }

    // -------------------------------------------------------------------------
    // Scout integration — Nova v5 parity (REA-1157)
    //
    // When the underlying model uses Laravel\Scout\Searchable, the resource
    // automatically uses Scout for searches. Override usesScout() to disable,
    // scoutQuery() to customise the Scout builder, and $scoutSearchResults
    // to limit Scout result count.
    // -------------------------------------------------------------------------

    /**
     * Maximum number of results returned by Scout search.
     *
     * Nova v5 parity: public static $scoutSearchResults = 200;
     * Set to null for Scout default behavior.
     */
    public static ?int $scoutSearchResults = null;

    /**
     * Determine whether this resource uses Laravel Scout for searching.
     *
     * By default, returns true when the associated model uses the
     * Laravel\Scout\Searchable trait. Override to return false to
     * force database search even when the model is Searchable.
     *
     * Nova v5 parity: public static function usesScout()
     */
    public static function usesScout(): bool
    {
        if (! trait_exists(Searchable::class)) {
            return false;
        }

        return in_array(
            Searchable::class,
            class_uses_recursive(static::model()),
            true
        );
    }

    /**
     * Customise the Scout builder before executing the search.
     *
     * Override this method to add constraints, filters or callbacks
     * to the Scout builder. Only called when the resource is
     * effectively using Scout (usesScout() returns true).
     *
     * Nova v5 parity: public static function scoutQuery(NovaRequest, ScoutBuilder): ScoutBuilder
     *
     * @param  mixed  $query  Scout builder instance
     * @return mixed Scout builder instance
     */
    public static function scoutQuery(Request $request, mixed $query): mixed
    {
        return $query;
    }

    // -------------------------------------------------------------------------
    // Page & Component Override System (REA-1171)
    //
    // Resources can declare overrides for any page view (create, edit, detail,
    // index) via dedicated methods. Each method returns either null (use default)
    // or an array with at least a 'component' key identifying the frontend
    // component to render, plus optional props passed to that component.
    //
    // This mirrors how Field::component() works for field-level overrides:
    //   - PHP Resource declares the override (component key + props)
    //   - Schema API exposes it
    //   - Frontend resolves and renders the specified component
    //
    // Override priority: explicit override > default page
    // -------------------------------------------------------------------------

    /**
     * Override the Create page for this resource.
     *
     * Return null to use the default create form, or an array with:
     *   - 'component' (string) - the frontend component key to render
     *   - any additional keys are passed as props to the component
     *
     * Example:
     *   public function overrideCreate(): ?array
     *   {
     *       return ['component' => 'sidebar-create', 'width' => 480];
     *   }
     *
     * @return array<string, mixed>|null
     */
    public function overrideCreate(): ?array
    {
        return null;
    }

    /**
     * Override the Edit page for this resource.
     *
     * @return array<string, mixed>|null
     */
    public function overrideEdit(): ?array
    {
        return null;
    }

    /**
     * Override the Detail page for this resource.
     *
     * @return array<string, mixed>|null
     */
    public function overrideDetail(): ?array
    {
        return null;
    }

    /**
     * Override the Index page for this resource.
     *
     * @return array<string, mixed>|null
     */
    public function overrideIndex(): ?array
    {
        return null;
    }

    /**
     * Collect all page overrides for serialization.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function overrides(): array
    {
        return [
            'create' => $this->overrideCreate(),
            'edit' => $this->overrideEdit(),
            'detail' => $this->overrideDetail(),
            'index' => $this->overrideIndex(),
        ];
    }
}
