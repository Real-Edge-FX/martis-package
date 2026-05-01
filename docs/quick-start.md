# Quick Start

Build your first Martis resource in five minutes. By the end you will have a fully working CRUD surface for a `Client` model with a searchable name, a sortable creation date, a coloured status badge, and one bulk action.

This guide assumes Martis is already installed. If not, see [Installation](installation-guide.md).

## 1. Generate the model and migration

```bash
php artisan make:model Client -m
```

Edit the migration:

```php
Schema::create('clients', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->enum('status', ['active', 'paused', 'archived'])->default('active');
    $table->timestamps();
});
```

Run it:

```bash
php artisan migrate
```

## 2. Generate a Martis resource

```bash
php artisan martis:resource Client
```

This creates `app/Martis/ClientResource.php`. Edit it to declare the fields:

```php
<?php

namespace App\Martis;

use App\Models\Client;
use Illuminate\Http\Request;
use Martis\Fields\Badge;
use Martis\Fields\Email;
use Martis\Fields\Id;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Resource;

class ClientResource extends Resource
{
    public static function model(): string
    {
        return Client::class;
    }

    /**
     * Attribute used in breadcrumbs, the global picker, and any place
     * Martis needs a human-readable label for a row. Defaults to `id`.
     */
    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Id::make('id'),
            Text::make('name')->sortable()->searchable()->required(),
            Email::make('email')->sortable()->searchable()->required(),

            // Editable on detail / create / update; hidden from the
            // index column because the Badge below already covers it.
            Select::make('status')
                ->options([
                    'Active'   => 'active',
                    'Paused'   => 'paused',
                    'Archived' => 'archived',
                ])
                ->displayUsingLabels()
                ->hideFromIndex(),

            // Read-only colour pill rendered only on the index column.
            // Built-in badge types: info, success, warning, danger.
            Badge::make('status')->map([
                'active'   => 'success',
                'paused'   => 'warning',
                'archived' => 'info',
            ])->onlyOnIndex(),
        ];
    }
}
```

## 3. That's it. Resources are auto-discovered.

Martis scans the directory configured in `config/martis.php` (`resources_path`, default `app_path('Martis')`) recursively on every boot and registers every class that extends `Martis\Resource`. There is no `'resources' => [...]` array to maintain. As soon as the file above is saved on disk, the resource is live.

If your editor created the file outside `app/Martis/` you have two options: move it back into the configured path, or change `resources_path` in the config to point wherever you keep your resource classes.

## 4. Visit the panel

Open `/martis` in your browser. You will see a `Clients` entry in the sidebar, the catalog page with sortable / searchable columns, the colour badge in the status column, and a working detail / edit page. No frontend build, no manual route registration, no cache clear.

## 5. Add a bulk action

```bash
php artisan martis:action ArchiveClients
```

This creates `app/Martis/Actions/ArchiveClients.php`. Replace the generated `handle()` body with the archive logic:

```php
<?php

namespace App\Martis\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Contracts\FieldContract;

class ArchiveClients extends Action
{
    /**
     * @param  Collection<int, \App\Models\Client>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        $models->each->update(['status' => 'archived']);

        return ActionResponse::message(count($models) . ' clients archived.');
    }

    /**
     * @return list<FieldContract>
     */
    public function fields(Request $request): array
    {
        return [];
    }
}
```

The return type is `ActionResponse|Action|null`. Use the static factories on `ActionResponse` to send a toast (`message`, `danger`), a redirect (`redirect`, `visit`), a download, a modal, or a client-side event.

Wire the action onto the resource:

```php
use App\Martis\Actions\ArchiveClients;

public function actions(Request $request): array
{
    return [new ArchiveClients()];
}
```

Reload the catalog. Select rows, open the action menu, archive in bulk.

## What's next

- [Fields](fields.md) — the full catalogue of 50 field types.
- [Relationships](relationships.md) — wire up `BelongsTo`, `HasMany`, `MorphToMany`, and the rest.
- [Filters](filters.md) and [lenses](lenses.md) — sticky views and alternative queries.
- [Metrics & dashboards](metrics.md) — value, trend, partition, progress, activity feed cards.
- [Override system](overrides.md) — replace any view, field, or layout without forking.
