<?php

namespace Martis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Contracts\OverrideContract;
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

    /**
     * The policy class to use for authorization on this resource.
     *
     * Set this property on a concrete resource to override auto-discovery
     * and the model's registered policy. When null, the resolution chain
     * falls through to auto-discovery and then the model policy.
     *
     * Nova v5 parity: public static $policy.
     *
     * @var class-string|null
     */
    public static ?string $policy = null;

    /**
     * Cached resolved policies by resource class.
     *
     * false = resolution ran but no policy found.
     * object = resolved policy instance.
     *
     * @var array<class-string, object|false>
     */
    private static array $resolvedPolicies = [];

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

    /**
     * Determine whether the current user can view trashed (soft-deleted) records.
     *
     * When false, the trashed filter dropdown is hidden and the backend
     * ignores ?trashed= query parameters. Override in concrete resources
     * to restrict visibility by role (e.g. admin-only).
     *
     * Nova v5 parity note: Nova shows the SoftDeletes filter for all users
     * by default. This method goes beyond Nova, giving per-resource control.
     */
    public static function canViewTrashed(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Authorization — Policy resolution (Nova v5 parity, REA-1115)
    // -------------------------------------------------------------------------

    /**
     * Determine whether authorization checks are enabled for this resource.
     *
     * Return false to skip all policy checks and allow all operations.
     * Nova v5 parity: public static function authorizable().
     */
    public static function authorizable(): bool
    {
        return true;
    }

    /**
     * Resolve the policy instance for this resource.
     *
     * Resolution order (Nova v5 parity):
     *   1. Explicit $policy property on the Resource class
     *   2. Auto-discovery: {policy_namespace}\{ResourceBaseName without 'Resource' suffix}Policy
     *   3. Laravel Gate policy registered for the model class
     *   4. null → no policy, permissive defaults apply
     *
     * Results are cached per resource class for the duration of the request.
     *
     * @return object|null The policy instance, or null if none found
     */
    public static function resolvePolicy(): ?object
    {
        $key = static::class;

        if (array_key_exists($key, self::$resolvedPolicies)) {
            $cached = self::$resolvedPolicies[$key];

            return $cached === false ? null : $cached;
        }

        // 1. Explicit $policy on the resource
        if (static::$policy !== null && class_exists(static::$policy)) {
            $policy = app(static::$policy);
            self::$resolvedPolicies[$key] = $policy;

            return $policy;
        }

        // 2. Auto-discovery by convention
        $namespace = (string) config('martis.policy_namespace', 'App\\Martis\\Policies');
        $resourceBaseName = class_basename(static::class);
        // Remove 'Resource' suffix: UserResource → User
        $baseName = preg_replace('/Resource$/', '', $resourceBaseName);
        $policyClass = $namespace.'\\'.$baseName.'Policy';

        if (class_exists($policyClass)) {
            $policy = app($policyClass);
            self::$resolvedPolicies[$key] = $policy;

            return $policy;
        }

        // 3. Laravel Gate policy for the model
        $modelPolicy = Gate::getPolicyFor(static::model());
        if ($modelPolicy !== null) {
            self::$resolvedPolicies[$key] = $modelPolicy;

            return $modelPolicy;
        }

        // 4. No policy found
        self::$resolvedPolicies[$key] = false;

        return null;
    }

    /**
     * Flush the resolved policy cache.
     *
     * Useful in tests to reset state between test cases.
     */
    public static function flushPolicyCache(): void
    {
        self::$resolvedPolicies = [];
    }

    // -------------------------------------------------------------------------
    // Authorization — Resource abilities
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

    /**
     * Determine whether the current user may restore this soft-deleted resource.
     *
     * Nova v5 parity: checks 'restore' policy method.
     * Default when missing: forbidden.
     */
    public function authorizedToRestore(Request $request): bool
    {
        return $this->checkPolicy('restore', $this->model);
    }

    /**
     * Determine whether the current user may force-delete this resource.
     *
     * Nova v5 parity: checks 'forceDelete' policy method.
     * Default when missing: forbidden.
     */
    public function authorizedToForceDelete(Request $request): bool
    {
        return $this->checkPolicy('forceDelete', $this->model);
    }

    /**
     * Determine whether the current user may replicate this resource.
     *
     * Nova v5 parity: checks 'replicate' policy method.
     * Fallback when missing: must pass BOTH create AND update.
     */
    public function authorizedToReplicate(Request $request): bool
    {
        $policy = static::resolvePolicy();

        if ($policy !== null && method_exists($policy, 'replicate')) {
            return $this->checkPolicy('replicate', $this->model);
        }

        // Fallback: must pass both create AND update
        return $this->authorizedToCreate($request) && $this->authorizedToUpdate($request);
    }

    /**
     * Determine whether the current user may run a normal action on this resource.
     *
     * Nova v5 parity: checks 'runAction' policy method.
     * Fallback when missing: delegates to authorizedToUpdate().
     */
    public function authorizedToRunAction(Request $request): bool
    {
        $policy = static::resolvePolicy();

        if ($policy !== null && method_exists($policy, 'runAction')) {
            return $this->checkPolicy('runAction', $this->model);
        }

        // Fallback to update
        return $this->authorizedToUpdate($request);
    }

    /**
     * Determine whether the current user may run a destructive action on this resource.
     *
     * Nova v5 parity: checks 'runDestructiveAction' policy method.
     * Fallback when missing: delegates to authorizedToDelete().
     */
    public function authorizedToRunDestructiveAction(Request $request): bool
    {
        $policy = static::resolvePolicy();

        if ($policy !== null && method_exists($policy, 'runDestructiveAction')) {
            return $this->checkPolicy('runDestructiveAction', $this->model);
        }

        // Fallback to delete
        return $this->authorizedToDelete($request);
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

    // -------------------------------------------------------------------------
    // Authorization — Core policy checking (REA-1115)
    // -------------------------------------------------------------------------

    /**
     * Check a relational policy ability.
     *
     * Uses the resolved policy (resource-specific → auto-discovered → model).
     * When no policy exists, or the policy does not define the method,
     * returns true (permissive default for relationship abilities).
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
        if (! static::authorizable()) {
            return true;
        }

        $policy = static::resolvePolicy();

        if ($policy === null) {
            return true;
        }

        $user = request()->user();
        if ($user === null) {
            return false;
        }

        // Honour the before() callback (Laravel convention)
        if (method_exists($policy, 'before')) {
            $beforeResult = $policy->before($user, $ability);
            if ($beforeResult !== null) {
                return (bool) $beforeResult;
            }
        }

        // If the policy does not define this specific method, allow by default
        if (! method_exists($policy, $ability)) {
            return true;
        }

        if ($this->model !== null && $relatedModel !== null) {
            return (bool) $policy->{$ability}($user, $this->model, $relatedModel);
        }

        if ($this->model !== null) {
            return (bool) $policy->{$ability}($user, $this->model);
        }

        return (bool) $policy->{$ability}($user);
    }

    /**
     * Check a policy ability for the resource's model.
     *
     * Uses the resolved policy (resource-specific → auto-discovered → model).
     * When no policy is registered, returns true (permissive default).
     * When a policy exists but the method is missing, applies the Nova v5
     * defaults matrix (see defaultForMissingAbility).
     *
     * @param  string  $ability  Policy method name (viewAny, view, create, update, delete, restore, forceDelete)
     * @param  Model|null  $model  The model instance (null for collection-level checks)
     */
    protected function checkPolicy(string $ability, ?Model $model): bool
    {
        if (! static::authorizable()) {
            return true;
        }

        $policy = static::resolvePolicy();

        if ($policy === null) {
            return true;
        }

        $user = request()->user();
        if ($user === null) {
            return false;
        }

        // Honour the before() callback (Laravel convention)
        if (method_exists($policy, 'before')) {
            $beforeResult = $policy->before($user, $ability);
            if ($beforeResult !== null) {
                return (bool) $beforeResult;
            }
        }

        if (! method_exists($policy, $ability)) {
            return $this->defaultForMissingAbility($ability);
        }

        if ($model !== null) {
            return (bool) $policy->{$ability}($user, $model);
        }

        return (bool) $policy->{$ability}($user);
    }

    /**
     * Default permission when a policy exists but does not define a method.
     *
     * Nova v5 parity defaults:
     *   viewAny              → allowed
     *   view                 → forbidden
     *   create               → forbidden
     *   update               → forbidden
     *   delete               → forbidden
     *   restore              → forbidden
     *   forceDelete          → forbidden
     *   add{Model}           → allowed
     *   attach{Model}        → allowed
     *   attachAny{Model}     → allowed
     *   detach{Model}        → allowed
     *   runAction            → (handled by authorizedToRunAction fallback)
     *   runDestructiveAction → (handled by authorizedToRunDestructiveAction fallback)
     */
    protected function defaultForMissingAbility(string $ability): bool
    {
        // viewAny is the only non-relational ability that defaults to allowed
        if ($ability === 'viewAny') {
            return true;
        }

        // Relationship abilities default to allowed
        if (str_starts_with($ability, 'add')
            || str_starts_with($ability, 'attach')
            || str_starts_with($ability, 'detach')) {
            return true;
        }

        // Everything else: forbidden
        return false;
    }

    /**
     * Return authorization metadata for this resource instance.
     *
     * Consumed by the frontend to show/hide action buttons and links.
     * Always derived from the backend policy — never trust frontend-only checks.
     *
     * @return array<string, bool>
     */
    public function authorizationMetadata(Request $request): array
    {
        $meta = [
            'authorizedToView' => $this->authorizedToView($request),
            'authorizedToUpdate' => $this->authorizedToUpdate($request),
            'authorizedToDelete' => $this->authorizedToDelete($request),
            'authorizedToReplicate' => $this->authorizedToReplicate($request),
            'authorizedToRunAction' => $this->authorizedToRunAction($request),
            'authorizedToRunDestructiveAction' => $this->authorizedToRunDestructiveAction($request),
        ];

        if (static::softDeletes()) {
            $meta['authorizedToRestore'] = $this->authorizedToRestore($request);
            $meta['authorizedToForceDelete'] = $this->authorizedToForceDelete($request);
        }

        return $meta;
    }

    /**
     * Return collection-level authorization metadata for schema responses.
     *
     * @return array<string, bool>
     */
    public function collectionAuthorizationMetadata(Request $request): array
    {
        return [
            'authorizedToViewAny' => $this->authorizedToViewAny($request),
            'authorizedToCreate' => $this->authorizedToCreate($request),
        ];
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

    /**
     * Customize the label for the actions menu in the resource index.
     * Override this to change "Actions" to a custom label.
     */
    public static function actionsMenuLabel(): ?string
    {
        return null;
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
     * Message shown after a record is force-deleted.
     */
    public static function forceDeletedMessage(): string
    {
        $msg = __('martis::messages.record_force_deleted');

        return is_string($msg) ? $msg : 'Record permanently deleted.';
    }

    /**
     * Message shown after a record is replicated.
     */
    public static function replicatedMessage(): string
    {
        $msg = __('martis::messages.record_replicated');

        return is_string($msg) ? $msg : 'Record duplicated successfully.';
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

    /**
     * Confirmation message shown before force-deleting a record.
     */
    public static function forceDeleteConfirmMessage(): string
    {
        $msg = __('martis::messages.force_delete_confirm');

        return is_string($msg) ? $msg : 'This will permanently delete the record. It cannot be recovered. Are you sure?';
    }

    // -------------------------------------------------------------------------
    // Page overrides — allows consumer to replace entire pages with custom
    // React components. Return null (default) to use built-in pages.
    // -------------------------------------------------------------------------

    /** Override the create page with a custom React component. */
    public function overrideCreate(): ?OverrideContract
    {
        return null;
    }

    /** Override the update page with a custom React component. */
    public function overrideUpdate(): ?OverrideContract
    {
        return null;
    }

    /** Override the detail page with a custom React component. */
    public function overrideDetail(): ?OverrideContract
    {
        return null;
    }

    /** Override the index page with a custom React component. */
    public function overrideIndex(): ?OverrideContract
    {
        return null;
    }

    /**
     * Collect all page overrides for the schema API.
     *
     * @return array{create: array{component: string, params: array<string, mixed>}|null, update: array{component: string, params: array<string, mixed>}|null, detail: array{component: string, params: array<string, mixed>}|null, index: array{component: string, params: array<string, mixed>}|null}
     */
    public function overrides(): array
    {
        return [
            'create' => $this->overrideCreate()?->toArray(),
            'update' => $this->overrideUpdate()?->toArray(),
            'detail' => $this->overrideDetail()?->toArray(),
            'index' => $this->overrideIndex()?->toArray(),
        ];
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
            'softDeletes' => static::softDeletes() && static::canViewTrashed(),
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
    // Actions — Nova v5 parity (REA-1102)
    // -------------------------------------------------------------------------

    /**
     * Return the actions available for this resource.
     *
     * @return list<ActionContract>
     */
    public function actions(Request $request): array
    {
        return [];
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
}
