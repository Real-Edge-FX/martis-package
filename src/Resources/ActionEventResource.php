<?php

namespace Martis\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Actions\ActionEventRedactor;
use Martis\Enums\SortDirection;
use Martis\Fields\Code;
use Martis\Fields\DateTime;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Models\ActionEvent;
use Martis\Resource;

/**
 * Built-in resource for browsing the martis_action_events audit log.
 *
 * Registered automatically by MartisServiceProvider when
 * config("martis.action_events.resource") is true (default).
 *
 * Users can hide this resource from the sidebar by overriding
 * displayInNavigation() or setting the config key to false.
 *
 * This resource is read-only: create, update and delete are disabled.
 *
 * Access. The audit log is closed by default, as the log holds what
 * every user changed. A policy for the ActionEvent model that defines
 * `viewAny` / `view` decides, as for any resource (Nova's
 * `ActionResource` follows the ActionEvent policy too); an ability the
 * policy does not define, or no policy at all, falls back to the
 * `view-martis-action-events` gate, which denies until the host
 * defines it. Without access the resource answers 403, leaves the
 * navigation and the command palette, and a relationship panel that
 * lists it (the "Action Events" panel of an `Actionable` model, or a
 * `MorphMany` declared by hand) leaves the detail page, its route
 * answering 403, as every relationship panel whose related resource the
 * user may not `viewAny`.
 *
 * Redaction. `original` and `changes` show a value only when the
 * viewer may see that attribute on the record's own detail page
 * ({@see ActionEventRedactor}); other values read `******`.
 */
class ActionEventResource extends Resource
{
    /** The gate that opens the audit log when no policy decides. */
    public const GATE = 'view-martis-action-events';

    /** {@inheritdoc} */
    public function authorizedToViewAny(Request $request): bool
    {
        if ($this->policyDefinesAbility('viewAny')) {
            return parent::authorizedToViewAny($request);
        }

        return static::gateAllows($request);
    }

    /** {@inheritdoc} */
    public function authorizedToView(Request $request): bool
    {
        if ($this->policyDefinesAbility('view')) {
            return parent::authorizedToView($request);
        }

        return static::gateAllows($request);
    }

    /** Whether the `view-martis-action-events` gate allows the request's user. */
    protected static function gateAllows(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows(static::GATE);
    }

    /** {@inheritdoc} */
    public static function globallySearchable(): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public static function model(): string
    {
        return ActionEvent::class;
    }

    /** {@inheritdoc} */
    public static function label(): string
    {
        return 'Action Events';
    }

    /** {@inheritdoc} */
    public static function singularLabel(): string
    {
        return 'Action Event';
    }

    /** {@inheritdoc} */
    public static function titleAttribute(): string
    {
        return 'name';
    }

    /** {@inheritdoc} */
    public static function defaultSort(): ?string
    {
        return 'created_at';
    }

    /** {@inheritdoc} */
    public static function defaultSortDirection(): SortDirection
    {
        return SortDirection::Desc;
    }

    /** {@inheritdoc} */
    public static function subtitle(): ?string
    {
        return 'Audit log of all actions executed in the admin panel';
    }

    /** {@inheritdoc} */
    public function icon(): string
    {
        return 'clipboard-text';
    }

    /** {@inheritdoc} */
    public function group(): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
    public function belongsToSystemSection(): bool
    {
        // Audit log lives in the System section alongside Cache admin
        // and (when scaffolded via `martis:roles`) the Roles, Permissions,
        // and Users resources. Closed until the host grants the
        // `view-martis-action-events` gate or an ActionEvent policy.
        return true;
    }

    // -------------------------------------------------------------------------
    // Read-only: disable create, update, delete
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Fields
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function fields(Request $request): array
    {
        return [
            Id::make('id'),

            Text::make('batch_id', 'Batch ID')
                ->hideFromIndex(),

            Text::make('user_id', 'User ID')
                ->sortable(),

            Text::make('name', 'Action')
                ->sortable()
                ->searchable(),

            Text::make('actionable_type', 'Actionable Type')
                ->hideFromIndex(),

            Text::make('actionable_id', 'Actionable ID')
                ->hideFromIndex(),

            Text::make('status', 'Status')
                ->sortable(),

            Textarea::make('exception', 'Exception')
                ->hideFromIndex()
                ->nullable(),

            Code::make('original', 'Original')
                ->json()
                ->resolveUsing(static fn (mixed $value, Model $model, string $attribute, ?Request $request = null): mixed => $model instanceof ActionEvent
                    ? ActionEventRedactor::redact($model, $value, $request ?? request())
                    : $value)
                ->hideFromIndex()
                ->nullable(),

            Code::make('changes', 'Changes')
                ->json()
                ->resolveUsing(static fn (mixed $value, Model $model, string $attribute, ?Request $request = null): mixed => $model instanceof ActionEvent
                    ? ActionEventRedactor::redact($model, $value, $request ?? request())
                    : $value)
                ->hideFromIndex()
                ->nullable(),

            DateTime::make('created_at', 'Executed At')
                ->sortable()
                ->exceptOnForms(),
        ];
    }

    /** {@inheritdoc} */
    public function fieldsForIndex(Request $request): array
    {
        return [
            Id::make('id'),

            Text::make('name', 'Action')
                ->sortable()
                ->searchable(),

            Text::make('user_id', 'User ID')
                ->sortable(),

            Text::make('actionable_type', 'Model')
                ->sortable(),

            Text::make('status', 'Status')
                ->sortable(),

            DateTime::make('created_at', 'Executed At')
                ->sortable(),
        ];
    }
}
