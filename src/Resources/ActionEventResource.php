<?php

namespace Martis\Resources;

use Illuminate\Http\Request;
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
 * This resource is read-only — create, update and delete are disabled.
 */
class ActionEventResource extends Resource
{
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
        // and Users resources. Admin-only via App\Policies\ActionEventPolicy
        // when the host registers one (see docs/policies.md); the
        // package itself stays unopinionated and exposes the resource.
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
                ->hideFromIndex()
                ->nullable(),

            Code::make('changes', 'Changes')
                ->json()
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
