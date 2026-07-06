<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — Models
// ---------------------------------------------------------------------------

class RecordUrlHeadlessWithUrlModel extends Model
{
    protected $table = 'record_url_headless_with_url_items';

    protected $fillable = ['title'];
}

class RecordUrlHeadlessNoUrlModel extends Model
{
    protected $table = 'record_url_headless_no_url_items';

    protected $fillable = ['title'];
}

class RecordUrlNormalModel extends Model
{
    protected $table = 'record_url_normal_items';

    protected $fillable = ['title'];
}

class RecordUrlDeniedHeadlessWithUrlModel extends Model
{
    protected $table = 'record_url_denied_headless_with_url_items';

    protected $fillable = ['title'];
}

class RecordUrlHeadlessWithSearchIndexModel extends Model
{
    protected $table = 'record_url_headless_with_search_index_items';

    protected $fillable = ['title'];
}

// ---------------------------------------------------------------------------
// Test fixtures — Resources
// ---------------------------------------------------------------------------

// Non-routable, but declares a recordUrl() — must join search, and its
// item/group URLs must resolve through recordHref().
class HeadlessWithUrlResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlHeadlessWithUrlModel::class;
    }

    public static function uriKey(): string
    {
        return 'record-url-headless-with-url-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    public static function recordUrl(): ?string
    {
        return '/tools/pk?id={id}';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

// Non-routable, no recordUrl() — must stay excluded from search (unchanged
// default behaviour for headless resources).
class HeadlessNoUrlResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlHeadlessNoUrlModel::class;
    }

    public static function uriKey(): string
    {
        return 'record-url-headless-no-url-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

// A normal resource: routable() defaults to true, recordUrl() defaults to
// null. Backward-compat anchor — its search shape must not change at all.
class NormalResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlNormalModel::class;
    }

    public static function uriKey(): string
    {
        return 'record-url-normal-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

// Non-routable, declares recordUrl(), but denies all authorization. Proves
// recordUrl() never bypasses the authorizedToViewAny() gate.
class DeniedHeadlessWithUrlResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlDeniedHeadlessWithUrlModel::class;
    }

    public static function uriKey(): string
    {
        return 'record-url-denied-headless-with-url-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    public static function recordUrl(): ?string
    {
        return '/tools/pk?id={id}';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

// Non-routable, declares recordUrl() AND searchIndexUrl() with a {search}
// placeholder — its "view all" affordance must resolve to the owning Tool,
// filtered by the searched term.
class HeadlessWithSearchIndexResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlHeadlessWithSearchIndexModel::class;
    }

    public static function uriKey(): string
    {
        return 'record-url-headless-with-search-index-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    public static function recordUrl(): ?string
    {
        return '/tools/normas?id={id}';
    }

    public static function searchIndexUrl(): ?string
    {
        return '/tools/normas?search={search}';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->searchable()->required(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('record_url_headless_with_url_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    Schema::create('record_url_headless_no_url_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    Schema::create('record_url_normal_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    Schema::create('record_url_denied_headless_with_url_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    Schema::create('record_url_headless_with_search_index_items', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->register(HeadlessWithUrlResource::class);
    $registry->register(HeadlessNoUrlResource::class);
    $registry->register(NormalResource::class);
    $registry->register(DeniedHeadlessWithUrlResource::class);
    $registry->register(HeadlessWithSearchIndexResource::class);

    RecordUrlHeadlessWithUrlModel::create(['title' => 'Findable Widget']);
    RecordUrlHeadlessNoUrlModel::create(['title' => 'Findable Widget']);
    RecordUrlNormalModel::create(['title' => 'Findable Widget']);
    RecordUrlDeniedHeadlessWithUrlModel::create(['title' => 'Findable Widget']);
    RecordUrlHeadlessWithSearchIndexModel::create(['title' => 'Findable Widget']);
});

afterEach(function () {
    Schema::dropIfExists('record_url_headless_with_url_items');
    Schema::dropIfExists('record_url_headless_no_url_items');
    Schema::dropIfExists('record_url_normal_items');
    Schema::dropIfExists('record_url_denied_headless_with_url_items');
    Schema::dropIfExists('record_url_headless_with_search_index_items');

    app(ResourceRegistry::class)->flush();
});

// ---------------------------------------------------------------------------
// Case 1: non-routable + recordUrl() joins search; url resolves via recordHref
// ---------------------------------------------------------------------------

it('includes a non-routable resource with recordUrl() in global search, resolving url via recordHref and nulling viewAllUrl', function () {
    $response = $this->getJson('/martis/api/search?q=Findable');

    $response->assertOk();

    $groups = collect($response->json('results'));
    $group = $groups->firstWhere('resource', 'record-url-headless-with-url-items');

    expect($group)->not->toBeNull();

    $item = RecordUrlHeadlessWithUrlModel::first();
    expect($group['items'][0]['url'])->toBe('/tools/pk?id='.$item->id);
    expect($group['viewAllUrl'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Case 2: non-routable, no recordUrl() — stays absent from search
// ---------------------------------------------------------------------------

it('excludes a non-routable resource without recordUrl() from global search', function () {
    $response = $this->getJson('/martis/api/search?q=Findable');

    $response->assertOk();

    $groups = collect($response->json('results'));
    $group = $groups->firstWhere('resource', 'record-url-headless-no-url-items');

    expect($group)->toBeNull();
});

// ---------------------------------------------------------------------------
// Case 3: backward-compat — normal (routable) resource is byte-for-byte
// unchanged: url stays /resources/{key}/{id}, viewAllUrl stays populated.
// ---------------------------------------------------------------------------

it('keeps a normal routable resource search result unchanged (backward-compat)', function () {
    $response = $this->getJson('/martis/api/search?q=Findable');

    $response->assertOk();

    $groups = collect($response->json('results'));
    $group = $groups->firstWhere('resource', 'record-url-normal-items');

    expect($group)->not->toBeNull();

    $item = RecordUrlNormalModel::first();
    expect($group['items'][0]['url'])->toBe('/resources/record-url-normal-items/'.$item->id);
    expect($group['viewAllUrl'])->toBe('/resources/record-url-normal-items?search=Findable');
});

// ---------------------------------------------------------------------------
// Case 4: security — recordUrl() never bypasses authorizedToViewAny()
// ---------------------------------------------------------------------------

it('excludes a non-routable resource with recordUrl() when authorizedToViewAny denies (security)', function () {
    $response = $this->getJson('/martis/api/search?q=Findable');

    $response->assertOk();

    $groups = collect($response->json('results'));
    $group = $groups->firstWhere('resource', 'record-url-denied-headless-with-url-items');

    expect($group)->toBeNull();
});

// ---------------------------------------------------------------------------
// Case 5: non-routable + searchIndexUrl() resolves the "view all" affordance
// to the owning Tool, filtered by the searched term (URL-encoded).
// ---------------------------------------------------------------------------

it('resolves viewAllUrl via searchIndexUrl() for a non-routable resource, interpolating the {search} term', function () {
    $response = $this->getJson('/martis/api/search?q=Findable');

    $response->assertOk();

    $groups = collect($response->json('results'));
    $group = $groups->firstWhere('resource', 'record-url-headless-with-search-index-items');

    expect($group)->not->toBeNull();
    expect($group['viewAllUrl'])->toBe('/tools/normas?search=Findable');
});
