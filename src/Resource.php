<?php

namespace Martis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Martis\Concerns\HasBadge;
use Martis\Concerns\HasGate;
use Martis\Contracts\ActionContract;
use Martis\Contracts\FieldContract;
use Martis\Contracts\OverrideContract;
use Martis\Contracts\ResourceContract;
use Martis\Contracts\UnsavedChangesConfigContract;
use Martis\Enums\DefaultRowAction;
use Martis\Enums\ErrorDisplayMode;
use Martis\Enums\SortDirection;
use Martis\Enums\TableLayout;
use Martis\Enums\TableSize;
use Martis\Events\AfterDelete;
use Martis\Events\AfterSave;
use Martis\Events\BeforeDelete;
use Martis\Events\BeforeSave;
use Martis\Menu\MenuItem;

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
    use HasBadge;
    use HasGate;

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

    /** Create a new resource instance, optionally binding an existing model. */
    public function __construct(?Model $model = null)
    {
        $this->model = $model;
    }

    // -------------------------------------------------------------------------
    // Abstract — must be implemented by concrete resources
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    abstract public function fields(Request $request): array;

    /** {@inheritdoc} */
    abstract public static function model(): string;

    // -------------------------------------------------------------------------
    // Defaults — may be overridden by concrete resources
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public static function newModel(): Model
    {
        $class = static::model();

        /** @var Model $instance */
        $instance = new $class;

        return $instance;
    }

    /** {@inheritdoc} */
    public static function uriKey(): string
    {
        return Str::plural(Str::kebab(class_basename(static::model())));
    }

    /** {@inheritdoc} */
    public static function label(): string
    {
        return Str::plural(Str::headline(class_basename(static::model())));
    }

    /** {@inheritdoc} */
    public static function singularLabel(): string
    {
        return Str::headline(class_basename(static::model()));
    }

    /** {@inheritdoc} */
    public static function subtitle(): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
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
    /** {@inheritdoc} */
    public static function indexSearchable(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public static function perPageOptions(): array
    {
        return [10, 25, 50, 100];
    }

    /** {@inheritdoc} */
    public static function perPage(): int
    {
        return (int) config('martis.pagination.default_per_page', 25);
    }

    /**
     * Resolve the effective per-page for this resource. When the developer's
     * declared `perPage()` is not present in `perPageOptions()`, clamp to
     * the first option so the dropdown and the actual filter stay in sync
     * (Option A — "options list is the source of truth").
     */
    public static function resolvedPerPage(): int
    {
        $options = static::perPageOptions();
        $perPage = static::perPage();

        if ($options === [] || in_array($perPage, $options, true)) {
            return $perPage;
        }

        return $options[0];
    }

    /** {@inheritdoc} */
    public static function defaultSort(): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
    public static function defaultSortDirection(): SortDirection
    {
        return SortDirection::Asc;
    }

    /** {@inheritdoc} */
    public static function searchPlaceholder(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Query hooks
    // -------------------------------------------------------------------------

    /**
     * Eager-load relations on every index, detail, and relatableQuery build.
     *
     * Mirrors the Laravel `$with` convention but at the Resource layer:
     * declare it once and Martis applies it on the listing query, the
     * relationship-picker query, and any `findModel()` lookup. Saves the
     * `indexQuery` / `relatableQuery` boilerplate that previously had to
     * wrap `$query->with([...])` for every Resource that needed eager
     * loading.
     *
     * Override `indexQuery()` directly when one surface needs a different
     * relation set — the explicit override wins.
     *
     * @var list<string>
     */
    protected static array $with = [];

    /**
     * Build a query for the resource index listing.
     *
     * Override this method to add tenant scoping, ownership filtering, or any
     * other structural query constraint for the index (list) view. This is
     * applied server-side before pagination and does NOT replace policies.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }

    /**
     * Declarative query scopes applied to every list-side query before
     * `indexQuery()` runs. v1.8.8.
     *
     * Return an associative `[label => Closure]` map; each closure
     * receives the `Builder` (and the `Request` if it declares the
     * second parameter) and returns the (possibly mutated) builder.
     *
     * Common multi-tenant pattern:
     *
     *     public static function scopes(Request $request): array
     *     {
     *         return [
     *             'tenant'  => fn (Builder $q) => $q->where('tenant_id', $request->user()->tenant_id),
     *             'visible' => fn (Builder $q) => $q->where('archived', false),
     *         ];
     *     }
     *
     * The label is purely informational (used by future debug overlays
     * and logging). The order is iteration order — array keys define a
     * stable apply order across reloads.
     *
     * Default `[]` keeps the resource untouched. Use `indexQuery()` for
     * one-off mutations that don't fit the declarative shape (joins,
     * raw SQL, conditional ordering); use `scopes()` for invariants
     * that should compose across every list endpoint.
     *
     * @return array<string, \Closure>
     */
    public static function scopes(Request $request): array
    {
        return [];
    }

    /**
     * Apply every closure returned by `scopes()` to the given query
     * in declaration order. Called by the controller before
     * `indexQuery()` so the manual hook can override scope-applied
     * predicates when really needed.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyScopes(Request $request, Builder $query): Builder
    {
        foreach (static::scopes($request) as $closure) {
            if (! is_callable($closure)) {
                continue;
            }
            $result = $closure($query, $request);
            if ($result instanceof Builder) {
                $query = $result;
            }
        }

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
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }

    /**
     * Apply the declarative `static $with` eager-load list onto a query.
     *
     * Called by every entry point that builds a model query (index, detail,
     * relatable selectors). Empty by default; overrides on consumer Resources
     * fill `static::$with` with the relations they always want hydrated.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyWith(Builder $query): Builder
    {
        if (static::$with !== []) {
            $query->with(static::$with);
        }

        return $query;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function fieldsForIndex(Request $request): array
    {
        return $this->fields($request);
    }

    /** {@inheritdoc} */
    public function fieldsForDetail(Request $request): array
    {
        return $this->fields($request);
    }

    /**
     * Fields surfaced in the detail page right rail (sticky 320px panel).
     *
     * Override to pin status, owner, dates and other "at-a-glance"
     * metadata away from the main field stack. Fields rendered here are
     * automatically removed from the main detail body so they don't
     * appear twice.
     *
     * Default: empty — meaning the detail page renders a single column
     * and no sidebar is shown. Returning a non-empty array switches
     * the layout to the canonical 1fr 320px grid.
     *
     * @return list<FieldContract>
     */
    public function detailSidebar(Request $request): array
    {
        return [];
    }

    /** {@inheritdoc} */
    public function fieldsForCreate(Request $request): array
    {
        return $this->fields($request);
    }

    /** {@inheritdoc} */
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
     * {@inheritdoc}
     */
    public function fieldsForInlineCreate(Request $request): array
    {
        return $this->fieldsForCreate($request);
    }

    /** {@inheritdoc} */
    public function fieldsForPreview(Request $request): array
    {
        return $this->fields($request);
    }

    // -------------------------------------------------------------------------
    // Schema foundation
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function filters(Request $request): array
    {
        return [];
    }

    /** {@inheritdoc} */
    public function lenses(Request $request): array
    {
        return [];
    }

    /** {@inheritdoc} */
    public function cards(Request $request): array
    {
        return [];
    }

    /** {@inheritdoc} */
    public static function dashboards(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Sticky Views
    // -------------------------------------------------------------------------

    /**
     * Per-resource opt-out for the global Sticky Views feature.
     *
     * When the global flag (`config('martis.sticky_views.enabled')`)
     * is `true`, every Resource opts in by default. Set this to
     * `false` on individual Resources whose tables should NOT
     * remember the previous view state across navigation (e.g.
     * audit logs that always want to show "today" first).
     *
     * Override:
     *
     * ```php
     * class ClientResource extends Resource
     * {
     *     protected static bool $stickyView = false;
     * }
     * ```
     */
    protected static bool $stickyView = true;

    /**
     * Returns whether the resource currently participates in the
     * Sticky Views feature. Combines the global config flag and the
     * per-resource override above. Surfaced to the React layer in
     * the schema payload as `stickyView` so `useStickyView()` can
     * branch without an extra round trip.
     */
    public static function stickyView(): bool
    {
        if (! (bool) config('martis.sticky_views.enabled', true)) {
            return false;
        }

        return static::$stickyView;
    }

    // -------------------------------------------------------------------------
    // Soft delete awareness
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
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
     * Martis extension: provides per-resource control over SoftDeletes filter
     * visibility so administrators can hide trashed records from selected roles.
     */
    public static function canViewTrashed(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Authorization — Policy resolution
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public static function authorizable(): bool
    {
        return true;
    }

    /**
     * Resolve the policy instance for this resource.
     *
     * Resolution order:
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

    /** {@inheritdoc} */
    public static function flushPolicyCache(): void
    {
        self::$resolvedPolicies = [];
    }

    // -------------------------------------------------------------------------
    // Authorization — Resource abilities
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function authorizedToViewAny(Request $request): bool
    {
        return $this->checkPolicy('viewAny', null);
    }

    /** {@inheritdoc} */
    public function authorizedToView(Request $request): bool
    {
        return $this->checkPolicy('view', $this->model);
    }

    /** {@inheritdoc} */
    public function authorizedToCreate(Request $request): bool
    {
        return $this->checkPolicy('create', null);
    }

    /** {@inheritdoc} */
    public function authorizedToUpdate(Request $request): bool
    {
        return $this->checkPolicy('update', $this->model);
    }

    /** {@inheritdoc} */
    public function authorizedToDelete(Request $request): bool
    {
        return $this->checkPolicy('delete', $this->model);
    }

    /** {@inheritdoc} */
    public function authorizedToRestore(Request $request): bool
    {
        return $this->checkPolicy('restore', $this->model);
    }

    /** {@inheritdoc} */
    public function authorizedToForceDelete(Request $request): bool
    {
        return $this->checkPolicy('forceDelete', $this->model);
    }

    /** {@inheritdoc} */
    public function authorizedToReplicate(Request $request): bool
    {
        $policy = static::resolvePolicy();

        if ($policy !== null && method_exists($policy, 'replicate')) {
            return $this->checkPolicy('replicate', $this->model);
        }

        // Fallback: must pass both create AND update
        return $this->authorizedToCreate($request) && $this->authorizedToUpdate($request);
    }

    /** {@inheritdoc} */
    public function authorizedToRunAction(Request $request): bool
    {
        $policy = static::resolvePolicy();

        if ($policy !== null && method_exists($policy, 'runAction')) {
            return $this->checkPolicy('runAction', $this->model);
        }

        // Fallback to update
        return $this->authorizedToUpdate($request);
    }

    /** {@inheritdoc} */
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
    // Relational authorization
    //
    // These methods check Model Policy abilities for relationship operations.
    // They coexist with relatableQuery / relatable{PluralModelName} hooks:
    //   - Policy methods = authorization (can the user do this action?)
    //   - Query hooks = structural filtering (which options are available?)
    // One does NOT replace the other.
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function authorizedToAttachAny(Request $request, string $relatedModelClass): bool
    {
        $ability = 'attachAny'.class_basename($relatedModelClass);

        return $this->checkRelationalPolicy($ability, $relatedModelClass);
    }

    /** {@inheritdoc} */
    public function authorizedToAttach(Request $request, Model $relatedModel): bool
    {
        $ability = 'attach'.class_basename($relatedModel);

        return $this->checkRelationalPolicy($ability, get_class($relatedModel), $relatedModel);
    }

    /** {@inheritdoc} */
    public function authorizedToDetach(Request $request, Model $relatedModel): bool
    {
        $ability = 'detach'.class_basename($relatedModel);

        return $this->checkRelationalPolicy($ability, get_class($relatedModel), $relatedModel);
    }

    /**
     * Determine whether the user may update pivot data for a related model.
     *
     * Martis feature: Policy method `updatePivot{Model}`.
     * When the policy method is absent, falls back to `update` on the parent
     * resource, because editing pivot columns is conceptually an edit of the
     * parent's relationship state.
     */
    public function authorizedToUpdatePivot(Request $request, Model $relatedModel): bool
    {
        $ability = 'updatePivot'.class_basename($relatedModel);

        if ($this->policyDefinesAbility($ability)) {
            return $this->checkRelationalPolicy($ability, get_class($relatedModel), $relatedModel);
        }

        return $this->authorizedToUpdate($request);
    }

    /** {@inheritdoc} */
    public function authorizedToAdd(Request $request, string $relatedModelClass): bool
    {
        $ability = 'add'.class_basename($relatedModelClass);

        return $this->checkRelationalPolicy($ability, $relatedModelClass);
    }

    // -------------------------------------------------------------------------
    // Authorization — Core policy checking
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
    /**
     * Whether the resolved policy actually implements the given ability.
     * Used when a relation ability needs to decide between a dedicated
     * policy method and a fallback to a simpler ability.
     */
    protected function policyDefinesAbility(string $ability): bool
    {
        if (! static::authorizable()) {
            return false;
        }

        $policy = static::resolvePolicy();

        return $policy !== null && method_exists($policy, $ability);
    }

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
     * When a policy exists but the method is missing, applies the
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
     * Defaults:
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * Return the navigation group for this resource (null = top-level).
     * Override to group resources in the sidebar.
     */
    /** {@inheritdoc} */
    public function icon(): string
    {
        return 'database';
    }

    /** {@inheritdoc} */
    public function iconColor(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // DataTable display configuration — configurable per resource
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public static function tableStriped(): bool
    {
        return true;
    }

    /**
     * Per-resource overrides for the loader (`MartisLoader`) on this resource's pages.
     *
     * Return a partial array that is merged on top of `config('martis.loader')`
     * before the schema payload is shipped to the frontend. Use it to give a
     * heavy resource a custom message ("Calibrating audit log…"), a brand
     * spinner colour different from the global accent, or a per-resource
     * `disableOn` toggle without touching global config.
     *
     * Recognised keys mirror `config/martis.php` `loader`:
     * `message`, `icon`, `logo`, `spinnerColor`, `overlayOpacity`,
     * `overlayColor`, `disabled`, `disableOn` (table | search | detail | components).
     *
     * Default: `[]` — no override; fall through to global config.
     *
     * @return array<string, mixed>
     */
    public static function loaderConfig(): array
    {
        return [];
    }

    /** {@inheritdoc} */
    public static function tableShowGridlines(): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public static function tableSize(): TableSize
    {
        return TableSize::Normal;
    }

    /**
     * How the DataTable distributes column widths.
     *
     * - `Auto` (default): browser sizes each column by content; per-field
     *   `->minWidth()` / `->maxWidth()` act as soft hints.
     * - `Fixed`: columns lock to the `->width()` the field declared (or
     *   the type default). Choose this only when you need pixel-perfect
     *   alignment across pages and can afford to width every column.
     */
    public static function tableLayout(): TableLayout
    {
        return TableLayout::Auto;
    }

    /** {@inheritdoc} */
    public static function tableRowHover(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public static function actionsMenuLabel(): ?string
    {
        return null;
    }

    /**
     * Controls the "unsaved changes" confirmation dialog shown by the
     * create/update surfaces (both drawer overrides and full-page
     * create/update routes) before discarding user input.
     *
     * Three return shapes:
     *   - `false` (default) → fully disabled; the form closes/navigates
     *     silently. Opt in per-resource.
     *   - `true` → enabled with the package defaults (generic copy).
     *   - {@see UnsavedChangesConfigContract}
     *     (e.g. {@see UnsavedChangesConfig}) → enabled AND
     *     overrides title / body / icon / colours / button labels.
     */
    public static function confirmUnsavedChanges(): bool|UnsavedChangesConfigContract
    {
        return false;
    }

    /**
     * Header label for the row-actions column on the resource index.
     *
     * Returns the translated default ("Actions" / "Ações") but resources
     * can override:
     *   - return `null` to hide the header text (column still appears)
     *   - return a custom string to rename it
     */
    public static function actionsColumnLabel(): ?string
    {
        try {
            $translated = trans('martis::actions.actions');
            if (is_string($translated) && $translated !== 'martis::actions.actions') {
                return $translated;
            }
        } catch (\Throwable) {
            // fall through to the hard-coded default
        }

        return 'Actions';
    }

    /** {@inheritdoc} */
    public static function bulkActionsMenuLabel(): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
    public function group(): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
    public function belongsToSystemSection(): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function menuItem(Request $request): MenuItem
    {
        return MenuItem::resource(static::class);
    }

    /** {@inheritdoc} */
    public static function displayInNavigation(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public static function showMenuCount(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public static function menuCount(Request $request): ?int
    {
        $query = static::newModel()->newQuery();

        // v1.8.8 — apply declarative scopes before the imperative hook
        // so the count badge agrees with the index-page row count.
        $query = static::applyScopes($request, $query);

        /** @var Builder<Model> $scoped */
        $scoped = static::indexQuery($request, $query);

        return (int) $scoped->toBase()->getCountForPagination();
    }

    // -------------------------------------------------------------------------
    // Notification messages — customizable per resource
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public static function createdMessage(): string
    {
        $msg = __('martis::messages.record_created');

        return is_string($msg) ? $msg : 'Record created successfully.';
    }

    /** {@inheritdoc} */
    public static function updatedMessage(): string
    {
        $msg = __('martis::messages.record_updated');

        return is_string($msg) ? $msg : 'Record updated successfully.';
    }

    /** {@inheritdoc} */
    public static function deletedMessage(): string
    {
        $msg = __('martis::messages.record_deleted');

        return is_string($msg) ? $msg : 'Record deleted successfully.';
    }

    /** {@inheritdoc} */
    public static function restoredMessage(): string
    {
        $msg = __('martis::messages.record_restored');

        return is_string($msg) ? $msg : 'Record restored successfully.';
    }

    /** {@inheritdoc} */
    public static function forceDeletedMessage(): string
    {
        $msg = __('martis::messages.record_force_deleted');

        return is_string($msg) ? $msg : 'Record permanently deleted.';
    }

    /** {@inheritdoc} */
    public static function replicatedMessage(): string
    {
        $msg = __('martis::messages.record_replicated');

        return is_string($msg) ? $msg : 'Record duplicated successfully.';
    }

    /** {@inheritdoc} */
    public static function deleteConfirmMessage(): string
    {
        $msg = __('martis::messages.delete_confirm');

        return is_string($msg) ? $msg : 'This action is permanent and cannot be undone. Are you sure?';
    }

    /** {@inheritdoc} */
    public static function archiveConfirmMessage(): string
    {
        $msg = __('martis::messages.archive_confirm');

        return is_string($msg) ? $msg : 'This record will be archived. You can restore it later.';
    }

    /** {@inheritdoc} */
    public static function forceDeleteConfirmMessage(): string
    {
        $msg = __('martis::messages.force_delete_confirm');

        return is_string($msg) ? $msg : 'This will permanently delete the record. It cannot be recovered. Are you sure?';
    }

    // -------------------------------------------------------------------------
    // Page overrides — allows consumer to replace entire pages with custom
    // React components. Return null (default) to use built-in pages.
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function overrideCreate(): ?OverrideContract
    {
        return null;
    }

    /** {@inheritdoc} */
    public function overrideUpdate(): ?OverrideContract
    {
        return null;
    }

    /** {@inheritdoc} */
    public function overrideDetail(): ?OverrideContract
    {
        return null;
    }

    /** {@inheritdoc} */
    public function overrideIndex(): ?OverrideContract
    {
        return null;
    }

    /** {@inheritdoc} */
    public function overrides(): array
    {
        return [
            'create' => $this->overrideCreate()?->toArray(),
            'update' => $this->overrideUpdate()?->toArray(),
            'detail' => $this->overrideDetail()?->toArray(),
            'index' => $this->overrideIndex()?->toArray(),
        ];
    }

    /** {@inheritdoc} */
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
            'accentColor' => static::accentColor(),
            'badge' => $this->badge(),
            'lock' => $this->lockPayloadNow(),
        ];
    }

    /**
     * Optional per-resource accent override. The frontend sets this as
     * `data-accent` on `<html>` when navigating to the resource and
     * restores the user's global preference on unmount, so a resource
     * can carry its own brand colour without polluting the rest of the
     * panel.
     *
     * Two value shapes are accepted:
     *
     *   - A built-in accent name (`'martis'`, `'blue'`, `'teal'`,
     *     `'violet'`, `'amber'`) — the matching `[data-accent]` rules
     *     in the bundled theme already define five token overrides.
     *   - A hex string (`'#DC143C'`) — the frontend assigns it to the
     *     `--martis-accent` custom property as an inline style on
     *     `<html>`, which wins over the `data-accent` selector. Use
     *     this when none of the built-ins match your brand.
     *
     * Default `null` keeps the user's global accent.
     *
     * Example:
     *
     *     class PaymentResource extends Resource
     *     {
     *         public static function accentColor(): ?string
     *         {
     *             return 'teal';
     *         }
     *     }
     */
    public static function accentColor(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Server-side hooks
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public static function errorDisplay(): ErrorDisplayMode
    {
        return ErrorDisplayMode::Inline;
    }

    /** {@inheritdoc} */
    public static function validationMessage(): string
    {
        $msg = __('martis::messages.validation_failed');

        return is_string($msg) ? $msg : 'The given data was invalid.';
    }

    /**
     * Optional override for the post-create destination URL.
     *
     * Returns the URL the SPA should navigate to after a successful create.
     * Returning `null` (the default) keeps the save-variant behaviour: the
     * primary "Create {Resource}" button lands on the new record's detail
     * page, "Create & view list" jumps to the index, "Create & add another"
     * stays on the create page. Override this hook when the post-save flow
     * should land somewhere outside the standard three options.
     *
     * The string is shipped to the frontend in the create response under
     * `meta.redirectTo`. The SPA only consults it when the operator clicked
     * the primary submit; "view list" and "add another" still win because
     * they encode an explicit user intent.
     */
    public function redirectAfterCreate(Model $model, Request $request): ?string
    {
        return null;
    }

    /**
     * Optional override for the post-update destination URL.
     *
     * Returns the URL the SPA should navigate to after a successful update.
     * Returning `null` (the default) keeps the save-variant behaviour: the
     * primary "Save changes" button lands on the record's detail page,
     * "Save & view list" jumps to the index, "Save & continue editing"
     * stays on the edit page. Override this hook when the post-save flow
     * should land somewhere outside the standard three options.
     *
     * The string is shipped to the frontend in the update response under
     * `meta.redirectTo`. The SPA only consults it when the operator clicked
     * the primary submit; "view list" and "continue editing" still win
     * because they encode an explicit user intent.
     */
    public function redirectAfterUpdate(Model $model, Request $request): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
    public function beforeSave(Model $model, Request $request, bool $creating): void
    {
        BeforeSave::dispatch(static::class, $model, $request, $creating);
    }

    /** {@inheritdoc} */
    public function afterSave(Model $model, Request $request, bool $creating): void
    {
        AfterSave::dispatch(static::class, $model, $request, $creating);
    }

    /** {@inheritdoc} */
    public function beforeDelete(Model $model, Request $request): void
    {
        BeforeDelete::dispatch(static::class, $model, $request);
    }

    /** {@inheritdoc} */
    public function afterDelete(Model $model, Request $request): void
    {
        AfterDelete::dispatch(static::class, $model, $request);
    }

    // -------------------------------------------------------------------------
    // Actions
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
    // Default row actions — Martis differential
    //
    // Render a column of built-in row actions (view, edit, delete) on the
    // resource index. Each icon is automatically disabled based on the row's
    // authorization payload, so unauthorized users see the action but cannot
    // trigger it. Custom inline actions (defined via actions() with showInline)
    // always render AFTER the defaults.
    //
    // Override in a concrete resource to opt out or pick a subset:
    //
    //   public function defaultRowActions(Request $request): bool|array
    //   {
    //       return false;              // hide column entirely for this resource
    //       return ['view', 'edit'];   // show only these two
    //   }
    // -------------------------------------------------------------------------

    /**
     * The default row actions for this resource.
     *
     * Returns `true` to use the global config, `false` to opt out, or a list
     * of {@see DefaultRowAction} cases to show a specific subset.
     *
     * @return bool|list<DefaultRowAction>
     */
    public function defaultRowActions(Request $request): bool|array
    {
        return true;
    }

    /**
     * Whether clicking on a row in the index opens the detail view.
     *
     * Return `null` to fall back to the global config
     * (`index.row_click_opens_detail`). Set to `false` to avoid redundancy
     * when the default row actions already expose a "view" icon.
     */
    public function rowClickOpensDetail(Request $request): ?bool
    {
        return null;
    }

    /**
     * Resolve whether row-click opens detail for this resource, merging the
     * per-resource override with the global config.
     */
    public function resolveRowClickOpensDetail(Request $request): bool
    {
        $override = $this->rowClickOpensDetail($request);

        if ($override !== null) {
            return $override;
        }

        return (bool) config('martis.index.row_click_opens_detail', true);
    }

    /**
     * Resolve the default row actions configuration for this resource,
     * merging the per-resource override with the global `enabled` switch.
     *
     * Visibility rules:
     *  - Global `enabled=false` in config/martis.php removes the whole
     *    actions column across the app.
     *  - Per-resource `defaultRowActions()` returning `false` opts out for
     *    that resource.
     *  - Per-resource `defaultRowActions()` returning an array whitelists
     *    which actions are eligible to appear (they still must pass policy).
     *  - When none of the above restricts, all three actions are eligible —
     *    per-row authorization decides whether each appears enabled,
     *    disabled, or hidden. The `hide*Action()` per-instance overrides on
     *    relationship fields are applied in the frontend shell, after this.
     *
     * @return array{enabled: bool, view: bool, edit: bool, delete: bool}
     */
    public function resolveDefaultRowActions(Request $request): array
    {
        $globallyEnabled = (bool) config('martis.index.default_row_actions.enabled', true);

        if (! $globallyEnabled) {
            return ['enabled' => false, 'view' => false, 'edit' => false, 'delete' => false];
        }

        // Per-action global kill-switches (`config('martis.index.default_row_actions.view|edit|delete')`).
        // Each defaults to true. Resource-level `defaultRowActions()` can
        // subtract further but never force a globally-disabled action back
        // on — the global flag wins via AND.
        $globalView = (bool) config('martis.index.default_row_actions.view', true);
        $globalEdit = (bool) config('martis.index.default_row_actions.edit', true);
        $globalDelete = (bool) config('martis.index.default_row_actions.delete', true);

        $resourceOverride = $this->defaultRowActions($request);

        if ($resourceOverride === false) {
            return ['enabled' => false, 'view' => false, 'edit' => false, 'delete' => false];
        }

        if (is_array($resourceOverride)) {
            return [
                'enabled' => true,
                'view' => $globalView && in_array(DefaultRowAction::View, $resourceOverride, true),
                'edit' => $globalEdit && in_array(DefaultRowAction::Edit, $resourceOverride, true),
                'delete' => $globalDelete && in_array(DefaultRowAction::Delete, $resourceOverride, true),
            ];
        }

        return [
            'enabled' => true,
            'view' => $globalView,
            'edit' => $globalEdit,
            'delete' => $globalDelete,
        ];
    }

    // -------------------------------------------------------------------------
    // Scout integration
    //
    // When the underlying model uses Laravel\Scout\Searchable, the resource
    // automatically uses Scout for searches. Override usesScout() to disable,
    // scoutQuery() to customise the Scout builder, and $scoutSearchResults
    // to limit Scout result count.
    // -------------------------------------------------------------------------

    /**
     * Maximum number of results returned by Scout search.
     *
     * Set to null for Scout default behavior.
     */
    public static ?int $scoutSearchResults = null;

    /**
     * Hard cap on the number of results returned by relatable lookups
     * (BelongsTo / MorphTo / BelongsToMany attach modal pickers).
     *
     * The request-level `?per_page=` parameter is still honoured but
     * never exceeds this cap. Default `null` means "no per-resource
     * cap" — the request `per_page` (or its 100-row hard ceiling) wins.
     *
     * Set to a small number (10–50) on resources with millions of rows
     * so the picker stays performant even when a power user pokes at
     * `?per_page=999`.
     *
     * Override per-resource:
     *
     *     public static int $relatableSearchResults = 25;
     */
    public static ?int $relatableSearchResults = null;

    /**
     * Resolve the effective per-page cap for relatable picker lookups.
     *
     * Returns the smaller of the request-supplied `per_page`, the
     * per-resource `$relatableSearchResults` (when set), and the
     * absolute hard ceiling (100) defined in `ResourceController`.
     */
    public static function resolveRelatableSearchResults(int $requestPerPage): int
    {
        $cap = static::$relatableSearchResults;

        if ($cap === null) {
            return max(1, min($requestPerPage, 100));
        }

        return max(1, min($requestPerPage, $cap, 100));
    }

    // -------------------------------------------------------------------------
    // Auto-refresh (polling) — index page
    // -------------------------------------------------------------------------

    /**
     * Auto-refresh the resource index view at a fixed cadence.
     *
     * When `true` the frontend re-fetches the index payload every
     * `$pollingInterval` seconds. Useful for resources whose rows reflect
     * external state (queue jobs, deployments, scraper feeds) where a
     * stale view is misleading.
     *
     * Override per-resource:
     *
     *     public static bool $polling = true;
     */
    public static bool $polling = false;

    /**
     * Auto-refresh interval in seconds. Only consulted when `$polling`
     * is `true`. Anything below 5 is silently clamped up to 5 to avoid
     * accidentally hammering the backend.
     */
    public static int $pollingInterval = 15;

    /**
     * Show a UI control next to the table that lets the user pause /
     * resume polling for the current session. When `false` polling is
     * always on (or always off, depending on `$polling`) with no
     * user override.
     */
    public static bool $showPollingToggle = true;

    /** Whether polling is enabled for this resource's index view. */
    public static function pollingEnabled(): bool
    {
        return static::$polling;
    }

    /** Auto-refresh cadence in seconds (clamped at 5s minimum). */
    public static function resolvedPollingInterval(): int
    {
        return max(5, static::$pollingInterval);
    }

    /** Whether the index UI exposes a pause/resume polling control. */
    public static function pollingToggleVisible(): bool
    {
        return static::$showPollingToggle;
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    public static function scoutQuery(Request $request, mixed $query): mixed
    {
        return $query;
    }

    // -------------------------------------------------------------------------
    // Global Search
    // -------------------------------------------------------------------------

    /**
     * Determine whether this resource is included in global search (Cmd+K).
     *
     * Two return shapes are supported:
     *
     * - **bool** (legacy): `true` to opt-in (uses `martis.search.default_limit`
     *   and `martis.search.min_query` for limits), `false` to exclude.
     * - **array** (override): `['enabled' => bool, 'limit' => int, 'min_query' => int]`.
     *   Any omitted key falls back to the global default. `enabled` defaults
     *   to true when an array is returned (so a resource can declare custom
     *   limits without having to repeat `'enabled' => true`).
     *
     * Examples:
     *
     *     // Opt-out entirely.
     *     public static function globallySearchable(): bool|array
     *     {
     *         return false;
     *     }
     *
     *     // Bump the per-resource cap to 10 results.
     *     public static function globallySearchable(): bool|array
     *     {
     *         return ['limit' => 10];
     *     }
     *
     *     // A resource where 1-character searches are useful (e.g. tags).
     *     public static function globallySearchable(): bool|array
     *     {
     *         return ['min_query' => 1];
     *     }
     *
     * @return bool|array{enabled?: bool, limit?: int, min_query?: int}
     */
    public static function globallySearchable(): bool|array
    {
        return true;
    }

    /** {@inheritdoc} */
    public function searchSubtitle(Model $model): ?string
    {
        return null;
    }

    /**
     * Image / avatar URL rendered to the left of the title in the
     * Global Search palette. Returning `null` (the default) keeps the
     * row icon-only, matching the legacy behaviour. Override per-
     * resource to surface gravatars, file thumbnails, or any other
     * visual cue that helps users disambiguate matches.
     *
     * Example:
     *
     *     public function searchImage(Model $model): ?string
     *     {
     *         return $model->avatar_url ?? gravatar($model->email);
     *     }
     */
    public function searchImage(Model $model): ?string
    {
        return null;
    }

    /**
     * Per-resource result transformer. The default builds the bundled
     * shape — id / title / subtitle / image / url. Override to add
     * arbitrary fields (e.g. status tags, badges) the host frontend
     * is prepared to render.
     *
     * Example:
     *
     *     public function globalSearchResult(Model $model): array
     *     {
     *         return [
     *             'id'       => $model->getKey(),
     *             'title'    => $this->title(),
     *             'subtitle' => $this->searchSubtitle($model),
     *             'image'    => $this->searchImage($model),
     *             'url'      => '/resources/'.static::uriKey().'/'.$model->getKey(),
     *             // Custom — pair with a frontend override that reads it.
     *             'status'   => $model->status,
     *         ];
     *     }
     *
     * @return array<string, mixed>
     */
    public function globalSearchResult(Model $model): array
    {
        return [
            'id' => $model->getKey(),
            'title' => $this->title(),
            'subtitle' => $this->searchSubtitle($model),
            'image' => $this->searchImage($model),
            'url' => '/resources/'.static::uriKey().'/'.$model->getKey(),
        ];
    }

    /**
     * Dot-notation relation paths searched alongside this resource's own
     * fields when Global Search runs a LIKE pipeline. Each entry is a
     * `relation.attribute` pair. The resolver joins (or falls back to a
     * `whereHas`) so the parent resource shows up when a related model's
     * attribute matches.
     *
     * Example — find an Order by its customer's name or email:
     *
     *     public static function searchableRelations(): array
     *     {
     *         return ['customer.name', 'customer.email'];
     *     }
     *
     * Defaults to `[]` (own fields only). Scout-backed resources ignore
     * this hook — Scout indexes whatever the model exposes via
     * `toSearchableArray()`.
     *
     * @return list<string>
     */
    public static function searchableRelations(): array
    {
        return [];
    }

    /**
     * Customise result ordering for global search.
     *
     * Called by `SearchController` AFTER the search filter has been
     * applied, so any `orderBy()` here runs on the filtered set. Defaults
     * to a no-op so the resource's `indexQuery()` ordering is preserved.
     *
     * Example — boost prefix matches over substring matches:
     *
     *     public function searchOrderBy(Builder $query, string $term): Builder
     *     {
     *         $like = addcslashes($term, '%_').'%';
     *
     *         return $query->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$like]);
     *     }
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function searchOrderBy(Builder $query, string $term): Builder
    {
        return $query;
    }
}
