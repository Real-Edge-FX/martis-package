<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Enums\TableLayout;
use Martis\Fields\Boolean;
use Martis\Fields\Date;
use Martis\Fields\DateTime;
use Martis\Fields\Email;
use Martis\Fields\Id;
use Martis\Fields\Status;
use Martis\Fields\Text;
use Martis\Fields\Url;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

it('exposes an empty column block when no width is declared', function () {
    $meta = Text::make('name')->toArray();

    expect($meta['column'])->toBe([
        'width' => null,
        'minWidth' => null,
        'maxWidth' => null,
        'truncate' => false,
    ]);
});

it('applies type defaults for id, email, url, boolean, date, datetime, status', function () {
    expect(Id::make()->toArray()['column']['width'])->toBe('80px');
    expect(Email::make('email')->toArray()['column']['maxWidth'])->toBe('280px');
    expect(Email::make('email')->toArray()['column']['truncate'])->toBeTrue();
    expect(Url::make('url')->toArray()['column']['maxWidth'])->toBe('280px');
    expect(Url::make('url')->toArray()['column']['truncate'])->toBeTrue();
    expect(Boolean::make('active')->toArray()['column']['width'])->toBe('120px');
    expect(Status::make('state')->toArray()['column']['width'])->toBe('120px');
    expect(Date::make('created_at')->toArray()['column']['width'])->toBe('140px');
    // DateTime inherits from Date.
    expect(DateTime::make('updated_at')->toArray()['column']['width'])->toBe('140px');
});

it('lets explicit fluent calls override the type defaults', function () {
    $meta = Email::make('email')->maxWidth('400px')->truncate(false)->toArray();

    expect($meta['column']['maxWidth'])->toBe('400px');
    // truncate(false) must not be re-enabled by the type default.
    expect($meta['column']['truncate'])->toBeFalse();
});

it('accepts width, minWidth, maxWidth and truncate fluent calls', function () {
    $meta = Text::make('note')
        ->width('200px')
        ->minWidth('160px')
        ->maxWidth('320px')
        ->truncate()
        ->toArray();

    expect($meta['column'])->toBe([
        'width' => '200px',
        'minWidth' => '160px',
        'maxWidth' => '320px',
        'truncate' => true,
    ]);
});

class ColumnWidthTestModel extends Model
{
    protected $table = 'column_width_test_items';

    protected $fillable = ['name'];
}

class ColumnWidthAutoResource extends Resource
{
    public static function model(): string
    {
        return ColumnWidthTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'column-width-auto';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Id::make(), Text::make('name')];
    }
}

class ColumnWidthFixedResource extends ColumnWidthAutoResource
{
    public static function uriKey(): string
    {
        return 'column-width-fixed';
    }

    public static function tableLayout(): TableLayout
    {
        return TableLayout::Fixed;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('column_width_test_items');
    Schema::create('column_width_test_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ColumnWidthAutoResource::class);
    $registry->register(ColumnWidthFixedResource::class);
});

afterEach(function () {
    Schema::dropIfExists('column_width_test_items');
});

it('defaults Resource::tableLayout() to auto and exposes it on the schema', function () {
    $response = $this->getJson('/martis/api/resources/column-width-auto/schema');

    $response->assertStatus(200);
    expect($response->json('data.tableLayout'))->toBe('auto');
});

it('propagates Resource::tableLayout() = fixed into the schema response', function () {
    $response = $this->getJson('/martis/api/resources/column-width-fixed/schema');

    expect($response->json('data.tableLayout'))->toBe('fixed');
});

it('seeds the title column with a 220px minWidth when nothing explicit is set', function () {
    $response = $this->getJson('/martis/api/resources/column-width-auto/schema');
    $fields = collect($response->json('data.fieldsForIndex'));
    $name = $fields->firstWhere('attribute', 'name');

    expect($name['column']['minWidth'])->toBe('220px');
});

it('skips type defaults when martis.index.column_defaults is disabled', function () {
    config()->set('martis.index.column_defaults', false);

    // Type default (Url → maxWidth 280 + truncate) is gone.
    $urlMeta = Url::make('site')->toArray();
    expect($urlMeta['column'])->toBe([
        'width' => null,
        'minWidth' => null,
        'maxWidth' => null,
        'truncate' => false,
    ]);

    // Id's default 80px width is gone.
    $idMeta = Id::make()->toArray();
    expect($idMeta['column']['width'])->toBeNull();
});

it('still honours explicit fluent calls when column_defaults is disabled', function () {
    config()->set('martis.index.column_defaults', false);

    $meta = Text::make('name')->maxWidth('320px')->truncate()->toArray();

    expect($meta['column']['maxWidth'])->toBe('320px');
    expect($meta['column']['truncate'])->toBeTrue();
});

it('does not inject the 220px title-column minWidth when column_defaults is disabled', function () {
    config()->set('martis.index.column_defaults', false);

    $response = $this->getJson('/martis/api/resources/column-width-auto/schema');
    $fields = collect($response->json('data.fieldsForIndex'));
    $name = $fields->firstWhere('attribute', 'name');

    expect($name['column']['minWidth'])->toBeNull();
});
