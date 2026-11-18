<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Fixtures
//
// A headless (`routable(false)`) resource is 404 on the schema endpoint, except
// when the request names a registered parent whose detail page declares a
// relationship field with that name pointing at it.
// ---------------------------------------------------------------------------

class HrsParentModel extends Model
{
    protected $table = 'hrs_parents';

    protected $fillable = ['name'];
}

class HrsHeadlessModel extends Model
{
    protected $table = 'hrs_headless_items';

    protected $fillable = ['title', 'sku'];
}

class HrsDeniedModel extends Model
{
    protected $table = 'hrs_denied_items';

    protected $fillable = ['title'];
}

class HrsOtherModel extends Model
{
    protected $table = 'hrs_other_items';

    protected $fillable = ['title'];
}

class HrsParentResource extends Resource
{
    public static function model(): string
    {
        return HrsParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-parents';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Items', 'items')->relatedResource('hrs-headless-items'),
            BelongsToMany::make('Tagged', 'tagged')->relatedResource('hrs-headless-items'),
            // Declares `others`, but its target is a different resource.
            HasMany::make('Others', 'others')->relatedResource('hrs-other-items'),
            HasMany::make('Denied', 'deniedItems')->relatedResource('hrs-denied-items'),
            HasMany::make('Gated', 'gatedItems')->relatedResource('hrs-gated-items'),
            // A relation inside a layout container. A raw field walk drops
            // everything nested in a Section, Panel or TabGroup, which has
            // regressed repeatedly; `Field::filterForContext()` flattens.
            Section::make('Nested', [
                HasMany::make('Nested items', 'nestedItems')->relatedResource('hrs-headless-items'),
            ]),
        ];
    }
}

// A parent the current user may not list: its relationships prove nothing.
class HrsLockedParentResource extends Resource
{
    public static function model(): string
    {
        return HrsParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-locked-parents';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Items', 'items')->relatedResource('hrs-headless-items'),
        ];
    }
}

// A parent that only shows the relationship on forms, never on the detail page.
class HrsNoDetailParentResource extends Resource
{
    public static function model(): string
    {
        return HrsParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-no-detail-parents';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Items', 'items')->relatedResource('hrs-headless-items')->hideFromDetail(),
        ];
    }
}

class HrsHeadlessResource extends Resource
{
    public static function model(): string
    {
        return HrsHeadlessModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-headless-items';
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
            Text::make('title'),
            Text::make('sku'),
        ];
    }
}

class HrsDeniedResource extends Resource
{
    public static function model(): string
    {
        return HrsDeniedModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-denied-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

// Lists fine when the parent's relationship field asks (the field's own
// `viewAny` gate), denies when the schema endpoint asks directly. That is the
// only way to reach the endpoint's own gate after a proven relationship, since
// a relationship field is hidden from a user who may not list its target.
class HrsGatedResource extends Resource
{
    public static function model(): string
    {
        return HrsDeniedModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-gated-items';
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public static function routable(): bool
    {
        return false;
    }

    /** Calls of authorizedToViewAny() so far, reset by the test. */
    public static int $viewAnyCalls = 0;

    /**
     * Allows the first call and denies the rest.
     *
     * A relationship field is hidden from the parent's detail fields when
     * the user cannot list its target, so a target that always denies is
     * never proven and answers 404. This one passes that gate (the first
     * call) and denies the endpoint's own check (the last), which is the
     * only way to reach the 403 branch. If the order of those two checks
     * ever changes, this test fails rather than passing silently.
     */
    public function authorizedToViewAny(Request $request): bool
    {
        return ++static::$viewAnyCalls === 1;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class HrsOtherResource extends Resource
{
    public static function model(): string
    {
        return HrsOtherModel::class;
    }

    public static function uriKey(): string
    {
        return 'hrs-other-items';
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
        return [Text::make('title')];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['hrs_parents' => 'name', 'hrs_denied_items' => 'title', 'hrs_other_items' => 'title'] as $table => $column) {
        Schema::create($table, function (Blueprint $t) use ($column) {
            $t->id();
            $t->string($column);
            $t->timestamps();
        });
    }

    Schema::create('hrs_headless_items', function (Blueprint $t) {
        $t->id();
        $t->string('title');
        $t->string('sku')->nullable();
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->register(HrsParentResource::class);
    $registry->register(HrsLockedParentResource::class);
    $registry->register(HrsNoDetailParentResource::class);
    $registry->register(HrsHeadlessResource::class);
    $registry->register(HrsDeniedResource::class);
    $registry->register(HrsGatedResource::class);
    $registry->register(HrsOtherResource::class);
});

afterEach(function () {
    foreach (['hrs_parents', 'hrs_headless_items', 'hrs_denied_items', 'hrs_other_items'] as $table) {
        Schema::dropIfExists($table);
    }

    app(ResourceRegistry::class)->flush();
});

function hrsSchemaUrl(string $resource, array $query = []): string
{
    $url = "/martis/api/resources/{$resource}/schema";

    return $query === [] ? $url : $url.'?'.http_build_query($query);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('keeps a headless resource schema 404 without via parameters', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items'))->assertNotFound();
});

it('serves the headless schema when a HasMany on the parent detail page names it', function () {
    $response = $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'items',
    ]));

    $response->assertOk();

    $columns = collect($response->json('data.fieldsForIndex'))->pluck('attribute')->all();
    expect($columns)->toContain('title')->toContain('sku');
});

it('serves the headless schema when a BelongsToMany on the parent detail page names it', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'tagged',
    ]))->assertOk()->assertJsonStructure(['data' => ['fieldsForIndex']]);
});

it('returns 404 when the parent does not declare the named relationship', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'nonexistent',
    ]))->assertNotFound();
});

it('returns 404 when the named relationship points at a different resource', function () {
    // `others` exists on the parent, but targets hrs-other-items, not the
    // requested hrs-headless-items.
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'others',
    ]))->assertNotFound();
});

it('returns 404 when viaResource is not registered', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'not-a-registered-resource',
        'viaRelationship' => 'items',
    ]))->assertNotFound();
});

it('returns 404 when only one of the via parameters is sent', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', ['viaResource' => 'hrs-parents']))->assertNotFound();
    $this->getJson(hrsSchemaUrl('hrs-headless-items', ['viaRelationship' => 'items']))->assertNotFound();
});

it('returns 404 when the via parameters are not plain strings', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => ['hrs-parents'],
        'viaRelationship' => ['items'],
    ]))->assertNotFound();
});

it('returns 404 when the parent denies viewAny to the user', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-locked-parents',
        'viaRelationship' => 'items',
    ]))->assertNotFound();
});

it('returns 404 when the relationship is not on the parent detail page', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-no-detail-parents',
        'viaRelationship' => 'items',
    ]))->assertNotFound();
});

it('never serves a target the user may not list: the relationship field is hidden, so it stays 404', function () {
    $this->getJson(hrsSchemaUrl('hrs-denied-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'deniedItems',
    ]))->assertNotFound();
});

it('still enforces the related resource own viewAny once the relationship is proven (403)', function () {
    // The parent's field resolves (the target lists for the field's gate), so
    // the relationship is proven; the endpoint's own viewAny check then denies.
    HrsGatedResource::$viewAnyCalls = 0;

    $this->getJson(hrsSchemaUrl('hrs-gated-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'gatedItems',
    ]))->assertForbidden();
});

it('proves a relationship declared inside a layout container', function () {
    $this->getJson(hrsSchemaUrl('hrs-headless-items', [
        'viaResource' => 'hrs-parents',
        'viaRelationship' => 'nestedItems',
    ]))->assertOk();
});

it('leaves a routable resource unaffected and answers 200 without via parameters', function () {
    $this->getJson(hrsSchemaUrl('hrs-parents'))->assertOk();
});

it('ignores the via parameters on a routable resource', function () {
    $this->getJson(hrsSchemaUrl('hrs-parents', [
        'viaResource' => 'not-a-registered-resource',
        'viaRelationship' => 'whatever',
    ]))->assertOk();
});
