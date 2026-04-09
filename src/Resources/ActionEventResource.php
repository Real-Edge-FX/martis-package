<?php

namespace Martis\Resources;

use Illuminate\Http\Request;
use Martis\Fields\DateTime;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Models\ActionEvent;
use Martis\Resource;

/**
 * Built-in resource for browsing the action_events audit log.
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
    public static function model(): string
    {
        return ActionEvent::class;
    }

    public static function label(): string
    {
        return 'Action Events';
    }

    public static function singularLabel(): string
    {
        return 'Action Event';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function defaultSort(): ?string
    {
        return 'created_at';
    }

    public static function defaultSortDirection(): string
    {
        return 'desc';
    }

    public static function subtitle(): ?string
    {
        return 'Audit log of all actions executed in the admin panel';
    }

    public function icon(): string
    {
        return 'clipboard-text';
    }

    public function group(): ?string
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Read-only: disable create, update, delete
    // -------------------------------------------------------------------------

    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Fields
    // -------------------------------------------------------------------------

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

            Textarea::make('original', 'Original')
                ->hideFromIndex()
                ->nullable(),

            Textarea::make('changes', 'Changes')
                ->hideFromIndex()
                ->nullable(),

            DateTime::make('created_at', 'Executed At')
                ->sortable()
                ->exceptOnForms(),
        ];
    }

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
