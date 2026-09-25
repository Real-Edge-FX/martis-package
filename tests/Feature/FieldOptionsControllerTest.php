<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Facades\Martis;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

// ── Fixtures ─────────────────────────────────────────────────────

class FieldOptionsTestModel extends Model
{
    protected $table = 'field_options_items';

    protected $guarded = [];

    public $timestamps = false;
}

/** @return array<string, string> value => label */
function fieldOptionsCatalog(string $term): array
{
    $all = ['gpt-4o', 'gpt-4o-mini', 'claude-opus-5', 'claude-sonnet-5'];
    $matches = array_values(array_filter($all, fn (string $m) => $term === '' || str_contains($m, $term)));

    return array_combine($matches, $matches);
}

class FieldOptionsTestResource extends Resource
{
    public static function model(): string
    {
        return FieldOptionsTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            // Nested in a layout container on purpose: the locator must flatten.
            Section::make('Provider', [
                Select::make('model')
                    ->options(['gpt-4o' => 'gpt-4o'])
                    ->searchOptionsUsing(fn (string $term, ?Request $r) => fieldOptionsCatalog($term)),
            ]),
            Select::make('plan')->options(['free' => 'Free', 'pro' => 'Pro'])->searchableOptions(),
        ];
    }

    public function fieldsForUpdate(Request $request): array
    {
        return array_merge($this->fields($request), [
            Select::make('upgrade_model')->searchOptionsUsing(fn (string $term) => ['upgrade' => 'upgrade']),
        ]);
    }
}

class FieldOptionsClampResource extends Resource
{
    public static ?string $seenTerm = null;

    public static function model(): string
    {
        return FieldOptionsTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('model')->searchOptionsUsing(function (string $term): array {
                static::$seenTerm = $term;

                return [];
            }),
        ];
    }
}

/**
 * Standard Laravel policy shape: `update()` REQUIRES the model. A gate that
 * calls it without one raises ArgumentCountError (a 500), which is exactly
 * what the update-context endpoints must never do.
 */
class FieldOptionsCreateDeniedPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function create($user): bool
    {
        return false;
    }

    public function update($user, Model $model): bool
    {
        // Ownership-style rule so the endpoint must bind the REAL record.
        return $model->name === 'editable';
    }
}

class FieldOptionsLockedModel extends Model
{
    protected $table = 'field_options_items';

    protected $guarded = [];

    public $timestamps = false;
}

class FieldOptionsLockedResource extends Resource
{
    public static ?string $policy = FieldOptionsCreateDeniedPolicy::class;

    public static function model(): string
    {
        return FieldOptionsLockedModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('model')->searchOptionsUsing(fn (string $term) => fieldOptionsCatalog($term)),
        ];
    }
}

class FieldOptionsTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Field Options Tool', uriKey: 'field-options-tool');
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('model')->searchOptionsUsing(fn (string $term) => fieldOptionsCatalog($term)),
            Select::make('plan')->options(['free' => 'Free', 'pro' => 'Pro']),
        ];
    }
}

class FieldOptionsPlainTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Plain Tool', uriKey: 'field-options-plain-tool');
    }
}

class FieldOptionsDeniedTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Denied Tool', uriKey: 'field-options-denied-tool');
        $this->canSee(fn (Request $r) => false);
    }

    public function fields(Request $request): array
    {
        return [Select::make('model')->searchOptionsUsing(fn (string $term) => fieldOptionsCatalog($term))];
    }
}

// ── Setup ────────────────────────────────────────────────────────

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('field_options_items');
    Schema::create('field_options_items', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('model')->nullable();
        $table->string('plan')->nullable();
        $table->string('upgrade_model')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(FieldOptionsTestResource::class);
    $registry->register(FieldOptionsLockedResource::class);

    Martis::tools([new FieldOptionsTool, new FieldOptionsPlainTool, new FieldOptionsDeniedTool]);
});

afterEach(function () {
    Schema::dropIfExists('field_options_items');
    Martis::tools([]);
    Resource::flushPolicyCache();
    FieldOptionsClampResource::$seenTerm = null;
});

function fieldOptionsUrl(string $field, string $query = ''): string
{
    return '/martis/api/resources/'.FieldOptionsTestResource::uriKey()."/fields/{$field}/options".($query !== '' ? "?{$query}" : '');
}

// ── Resource endpoint ────────────────────────────────────────────

it('returns the resolver output for the search term, finding the field inside a layout container', function () {
    $response = $this->getJson(fieldOptionsUrl('model', 'search=claude&context=create'));

    $response->assertOk();
    expect($response->json('data.options'))->toEqual([
        ['label' => 'claude-opus-5', 'value' => 'claude-opus-5'],
        ['label' => 'claude-sonnet-5', 'value' => 'claude-sonnet-5'],
    ]);
});

it('treats a missing search param as an empty term and defaults the context to create', function () {
    $response = $this->getJson(fieldOptionsUrl('model'));

    $response->assertOk();
    expect(collect($response->json('data.options'))->pluck('value')->all())
        ->toEqual(['gpt-4o', 'gpt-4o-mini', 'claude-opus-5', 'claude-sonnet-5']);
});

it('trims the term and clamps it to 255 characters before calling the resolver', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(FieldOptionsClampResource::class);

    $url = '/martis/api/resources/'.FieldOptionsClampResource::uriKey().'/fields/model/options?search='.urlencode('  '.str_repeat('a', 300).'  ');
    $this->getJson($url)->assertOk();

    expect(FieldOptionsClampResource::$seenTerm)->toBe(str_repeat('a', 255));
});

it('rejects an unknown field with 422', function () {
    $this->getJson(fieldOptionsUrl('nope'))->assertStatus(422);
});

it('rejects a field that is not a Select with 422', function () {
    $this->getJson(fieldOptionsUrl('name'))->assertStatus(422);
});

it('rejects a Select without a server-side resolver with 422', function () {
    $this->getJson(fieldOptionsUrl('plan'))->assertStatus(422);
});

it('looks the field up in the field set of the requested context', function () {
    $record = FieldOptionsTestModel::create(['name' => 'x']);
    $this->getJson(fieldOptionsUrl('upgrade_model', 'context=create'))->assertStatus(422);

    $update = $this->getJson(fieldOptionsUrl('upgrade_model', 'context=update&id='.$record->id));
    $update->assertOk();
    expect($update->json('data.options'))->toEqual([['label' => 'upgrade', 'value' => 'upgrade']]);
});

it('gates on the ability that matches the context, binding the record for update', function () {
    $this->actingAs((new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test User']));
    $editable = FieldOptionsLockedModel::create(['name' => 'editable']);
    $locked = FieldOptionsLockedModel::create(['name' => 'locked']);
    $base = '/martis/api/resources/'.FieldOptionsLockedResource::uriKey().'/fields/model/options';

    $this->getJson($base.'?context=create')->assertStatus(403);
    // The policy receives the bound record, as any Laravel policy expects.
    $this->getJson($base.'?context=update&id='.$editable->id)->assertOk();
    $this->getJson($base.'?context=update&id='.$locked->id)->assertStatus(403);
});

it('update context needs a record id and 404s an unknown one', function () {
    $this->getJson(fieldOptionsUrl('model', 'context=update'))->assertStatus(422);
    $this->getJson(fieldOptionsUrl('model', 'context=update&id=999999'))->assertStatus(404);
});

it('returns 404 for an unknown resource', function () {
    $this->getJson('/martis/api/resources/nope/fields/model/options')->assertStatus(404);
});

// ── Tool endpoint ────────────────────────────────────────────────

it('serves a Tool select through the tool endpoint', function () {
    $response = $this->getJson('/martis/api/tools/field-options-tool/fields/model/options?search=gpt');

    $response->assertOk();
    expect(collect($response->json('data.options'))->pluck('value')->all())->toEqual(['gpt-4o', 'gpt-4o-mini']);
});

it('rejects a Tool select without a resolver with 422', function () {
    $this->getJson('/martis/api/tools/field-options-tool/fields/plan/options')->assertStatus(422);
});

it('rejects a field on a Tool that does not implement ProvidesFields with 422', function () {
    $this->getJson('/martis/api/tools/field-options-plain-tool/fields/model/options')->assertStatus(422);
});

it('returns 404 (not 403) when canSee() denies the Tool, and for an unknown Tool', function () {
    $this->getJson('/martis/api/tools/field-options-denied-tool/fields/model/options')->assertStatus(404);
    $this->getJson('/martis/api/tools/nope/fields/model/options')->assertStatus(404);
});
