<?php

namespace Martis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Contracts\ResourceContract;
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

    /**
     * Return a fresh (unsaved) model instance.
     */
    public static function newModel(): Model
    {
        $class = static::model();

        /** @var Model $instance */
        $instance = new $class;

        return $instance;
    }

    /**
     * Return the URL key used in route segments.
     * Derived from model class name: BlogPost → "blog-posts".
     */
    public static function uriKey(): string
    {
        return Str::plural(Str::kebab(class_basename(static::model())));
    }

    /**
     * Return the plural human-readable label.
     * Derived from model class name: BlogPost → "Blog Posts".
     */
    public static function label(): string
    {
        return Str::plural(Str::headline(class_basename(static::model())));
    }

    /**
     * Return the singular human-readable label.
     * Derived from model class name: BlogPost → "Blog Post".
     */
    public static function singularLabel(): string
    {
        return Str::headline(class_basename(static::model()));
    }

    // -------------------------------------------------------------------------
    // Context-aware field resolution
    // -------------------------------------------------------------------------

    /**
     * Return only fields visible on the index (list) view.
     *
     * @return list<FieldContract>
     */
    public function fieldsForIndex(Request $request): array
    {
        return array_values(array_filter(
            $this->fields($request),
            fn (FieldContract $field): bool => $field->isShownOnIndex()
        ));
    }

    /**
     * Return only fields visible on the detail (show) view.
     *
     * @return list<FieldContract>
     */
    public function fieldsForDetail(Request $request): array
    {
        return array_values(array_filter(
            $this->fields($request),
            fn (FieldContract $field): bool => $field->isShownOnDetail()
        ));
    }

    /**
     * Return only fields visible on create and edit forms.
     *
     * @return list<FieldContract>
     */
    public function fieldsForForms(Request $request): array
    {
        return array_values(array_filter(
            $this->fields($request),
            fn (FieldContract $field): bool => $field->isShownOnForms()
        ));
    }

    // -------------------------------------------------------------------------
    // Soft delete awareness
    // -------------------------------------------------------------------------

    /**
     * Determine whether the associated model uses soft deletes.
     */
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

    /**
     * Return the underlying model instance.
     */
    public function getModel(): ?Model
    {
        return $this->model;
    }

    /**
     * Return the navigation group for this resource (null = top-level).
     * Override to group resources in the sidebar.
     */
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
     * Serialize resource metadata (not field data) to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uriKey' => static::uriKey(),
            'label' => static::label(),
            'singularLabel' => static::singularLabel(),
            'softDeletes' => static::softDeletes(),
            'group' => $this->group(),
        ];
    }

    // -------------------------------------------------------------------------
    // Server-side hooks — Bloco 9
    // -------------------------------------------------------------------------

    /**
     * Called immediately before the model is saved (create or update).
     *
     * Override in concrete resources to add custom logic:
     *   public function beforeSave(Model $model, Request $request, bool $creating): void
     *   {
     *       $model->slug = Str::slug($model->title);
     *   }
     *
     * The BeforeSave event is also dispatched for listener-based decoupling.
     */
    public function beforeSave(Model $model, Request $request, bool $creating): void
    {
        BeforeSave::dispatch(static::class, $model, $request, $creating);
    }

    /**
     * Called immediately after the model is saved (create or update).
     *
     * Override to trigger side effects (cache busting, notifications, jobs).
     * The AfterSave event is also dispatched.
     */
    public function afterSave(Model $model, Request $request, bool $creating): void
    {
        AfterSave::dispatch(static::class, $model, $request, $creating);
    }

    /**
     * Called immediately before the model is deleted (or soft-deleted).
     *
     * Override to guard deletions or cascade cleanup.
     * The BeforeDelete event is also dispatched.
     */
    public function beforeDelete(Model $model, Request $request): void
    {
        BeforeDelete::dispatch(static::class, $model, $request);
    }

    /**
     * Called immediately after the model is deleted (or soft-deleted).
     *
     * Override to clean up related data, update caches, etc.
     * The AfterDelete event is also dispatched.
     */
    public function afterDelete(Model $model, Request $request): void
    {
        AfterDelete::dispatch(static::class, $model, $request);
    }
}
