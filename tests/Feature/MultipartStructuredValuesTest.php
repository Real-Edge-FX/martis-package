<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\Boolean;
use Martis\Fields\BooleanGroup;
use Martis\Fields\Field;
use Martis\Fields\HasMany;
use Martis\Fields\Image;
use Martis\Fields\KeyValue;
use Martis\Fields\MorphTo;
use Martis\Fields\MultiSelect;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Select;
use Martis\Fields\Sparkline;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Controllers\Concerns\DecodesStructuredValues;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Structured values survive the multipart request path.
//
// As soon as a form uploads a file the SPA sends multipart/form-data, and
// FormData carries strings only: every list or map value is JSON-encoded
// (`buildFormData()`), and the controllers decode it back for the fields
// that declare `hasStructuredValue()` before validation and fill. These
// tests drive the update endpoint exactly as the SPA does: POST +
// `_method=PUT`, one uploaded file, JSON strings for the structured
// fields, '1' / '0' for booleans.
// ===========================================================================

class MSVSection extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            Select::make('key', 'Section')->options(['hero' => 'Hero', 'stats' => 'Stats']),
            Boolean::make('enabled', 'Enabled'),
        ];
    }
}

class MSVSiteModel extends Model
{
    protected $table = 'msv_sites';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'sections' => 'array',
        'states' => 'array',
        'effects' => 'array',
        'hours' => 'array',
        'published' => 'boolean',
    ];
}

class MSVSiteResource extends Resource
{
    public static function model(): string
    {
        return MSVSiteModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Image::make('logo')->disk('msv_disk')->storagePath('logos')->nullable(),
            Repeater::make('sections')->asJson()->repeatables([MSVSection::make()]),
            MultiSelect::make('states')->options(['available' => 'Available', 'reserved' => 'Reserved', 'sold' => 'Sold'])->rules(['nullable', 'array', 'max:2']),
            BooleanGroup::make('effects')->options(['blur' => 'Blur', 'grain' => 'Grain']),
            KeyValue::make('hours'),
            Boolean::make('published'),
        ];
    }
}

class MSVOwnerModel extends Model
{
    protected $table = 'msv_owners';

    protected $guarded = [];

    public $timestamps = false;

    public function notes(): Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MSVNoteModel::class, 'owner_id');
    }
}

class MSVNoteModel extends Model
{
    protected $table = 'msv_notes';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['meta' => 'array'];
}

class MSVOwnerResource extends Resource
{
    public static function model(): string
    {
        return MSVOwnerModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Notes', 'notes')->relatedResource('m-s-v-note-models'),
        ];
    }
}

class MSVNoteResource extends Resource
{
    public static function model(): string
    {
        return MSVNoteModel::class;
    }

    public function fields(Request $request): array
    {
        return [KeyValue::make('meta')];
    }
}

$sections = [
    ['id' => 'a', 'type' => 'm-s-v-section', 'fields' => ['key' => 'hero', 'enabled' => true]],
    ['id' => 'b', 'type' => 'm-s-v-section', 'fields' => ['key' => 'stats', 'enabled' => false]],
];

beforeEach(function () use ($sections) {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Storage::fake('msv_disk');

    Schema::dropIfExists('msv_sites');
    Schema::create('msv_sites', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('logo')->nullable();
        $table->json('sections')->nullable();
        $table->json('states')->nullable();
        $table->json('effects')->nullable();
        $table->json('hours')->nullable();
        $table->boolean('published')->default(false);
    });

    Schema::dropIfExists('msv_notes');
    Schema::dropIfExists('msv_owners');
    Schema::create('msv_owners', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('msv_notes', function ($table) {
        $table->id();
        $table->unsignedBigInteger('owner_id');
        $table->json('meta')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MSVSiteResource::class);
    $registry->register(MSVOwnerResource::class);
    $registry->register(MSVNoteResource::class);

    $this->site = MSVSiteModel::create([
        'name' => 'Acme',
        'sections' => $sections,
        'states' => ['available', 'reserved'],
        'effects' => ['blur' => true, 'grain' => false],
        'hours' => ['mon' => '09:00-18:00', 'sun' => 'closed'],
        'published' => true,
    ]);
});

afterEach(function () {
    Schema::dropIfExists('msv_sites');
    Schema::dropIfExists('msv_notes');
    Schema::dropIfExists('msv_owners');
});

function msvMultipartUpdate($test, int $id, array $parameters, array $files = [])
{
    return $test->call(
        'POST',
        "/martis/api/resources/m-s-v-site-models/{$id}",
        ['_method' => 'PUT'] + $parameters,
        [],
        $files,
        ['HTTP_ACCEPT' => 'application/json'],
    );
}

it('keeps Repeater rows, a MultiSelect selection, a BooleanGroup map and an unchecked Boolean intact when a file is uploaded', function () use ($sections) {
    $response = msvMultipartUpdate($this, $this->site->id, [
        'name' => 'Acme',
        'sections' => json_encode($sections),
        'states' => '["available","reserved"]',
        'effects' => '{"blur":true,"grain":false}',
        'published' => '0',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(200);

    $fresh = $this->site->fresh();
    expect($fresh->sections)->toBe($sections)
        ->and($fresh->states)->toBe(['available', 'reserved'])
        ->and($fresh->effects)->toBe(['blur' => true, 'grain' => false])
        ->and($fresh->published)->toBeFalse()
        ->and($fresh->logo)->toStartWith('logos/');
});

it('applies structured changes sent alongside a file', function () {
    $response = msvMultipartUpdate($this, $this->site->id, [
        'sections' => '[{"id":"b","type":"m-s-v-section","fields":{"key":"stats","enabled":true}}]',
        'states' => '["sold"]',
        'effects' => '{"blur":false,"grain":true}',
        'published' => '1',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(200);

    $fresh = $this->site->fresh();
    expect($fresh->sections)->toHaveCount(1)
        ->and($fresh->sections[0]['fields'])->toBe(['key' => 'stats', 'enabled' => true])
        ->and($fresh->states)->toBe(['sold'])
        ->and($fresh->effects)->toBe(['blur' => false, 'grain' => true])
        ->and($fresh->published)->toBeTrue();
});

it('clears a MultiSelect and empties a Repeater from their encoded empty forms', function () {
    $response = msvMultipartUpdate($this, $this->site->id, [
        'sections' => '[]',
        'states' => '[]',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(200);

    $fresh = $this->site->fresh();
    expect($fresh->sections)->toBe([])
        ->and($fresh->states)->toBeNull();
});

it('validates the decoded structure with the field rules (422 on a MultiSelect over its max)', function () {
    $response = msvMultipartUpdate($this, $this->site->id, [
        'states' => '["available","reserved","sold"]',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(422);
    expect($this->site->fresh()->states)->toBe(['available', 'reserved']);
});

it('rejects a stringified structure that is not JSON instead of storing it', function () {
    $response = msvMultipartUpdate($this, $this->site->id, [
        'states' => '12,15',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(422);
    expect($this->site->fresh()->states)->toBe(['available', 'reserved']);
});

it('rejects a non-JSON string for structured fields that declare no array rule, leaving the stored values untouched', function () use ($sections) {
    // Repeater, BooleanGroup and KeyValue carry no `array` rule of their
    // own: the string a pre-1.37.3 bundle sent must still fail validation
    // rather than reach fill(), which would empty the rows, store the text
    // or clear the map.
    $response = msvMultipartUpdate($this, $this->site->id, [
        'sections' => '[object Object],[object Object]',
        'effects' => '[object Object]',
        'hours' => '[object Object],[object Object]',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toBe(['sections', 'effects', 'hours']);

    $fresh = $this->site->fresh();
    expect($fresh->sections)->toBe($sections)
        ->and($fresh->effects)->toBe(['blur' => true, 'grain' => false])
        ->and($fresh->hours)->toBe(['mon' => '09:00-18:00', 'sun' => 'closed'])
        ->and($fresh->logo)->toBeNull();
});

it('rejects a non-JSON string for a structured field on the JSON request path too', function () {
    $response = $this->putJson("/martis/api/resources/m-s-v-site-models/{$this->site->id}", [
        'hours' => 'mon=09:00',
    ]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toBe(['hours']);
    expect($this->site->fresh()->hours)->toBe(['mon' => '09:00-18:00', 'sun' => 'closed']);
});

it('rejects a non-JSON string for a structured field on the inline HasMany endpoints', function () {
    $owner = MSVOwnerModel::create(['name' => 'Acme']);
    $note = $owner->notes()->create(['meta' => ['a' => '1']]);
    $base = "/martis/api/resources/m-s-v-owner-models/{$owner->id}/has-many/notes";

    $store = $this->postJson($base, ['meta' => '[object Object]']);
    $store->assertStatus(422);
    expect(collect($store->json('errors'))->pluck('field')->all())->toBe(['meta'])
        ->and($owner->notes()->count())->toBe(1);

    $update = $this->putJson("{$base}/{$note->id}", ['meta' => '[object Object]']);
    $update->assertStatus(422);
    expect($note->fresh()->meta)->toBe(['a' => '1']);

    $this->putJson("{$base}/{$note->id}", ['meta' => '{"a":"2","b":"3"}'])->assertStatus(200);
    expect($note->fresh()->meta)->toBe(['a' => '2', 'b' => '3']);
});

it('still clears a structured field from the empty string the multipart path sends for null', function () {
    $response = msvMultipartUpdate($this, $this->site->id, [
        'hours' => '',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)]);

    $response->assertStatus(200);
    expect($this->site->fresh()->hours)->toBeNull();
});

it('still accepts the JSON request path unchanged', function () {
    $response = $this->putJson("/martis/api/resources/m-s-v-site-models/{$this->site->id}", [
        'sections' => [['id' => 'a', 'type' => 'm-s-v-section', 'fields' => ['key' => 'hero', 'enabled' => false]]],
        'states' => ['sold'],
        'effects' => ['blur' => false, 'grain' => true],
        'published' => false,
    ]);

    $response->assertStatus(200);

    $fresh = $this->site->fresh();
    expect($fresh->sections[0]['fields']['enabled'])->toBeFalse()
        ->and($fresh->states)->toBe(['sold'])
        ->and($fresh->effects)->toBe(['blur' => false, 'grain' => true])
        ->and($fresh->published)->toBeFalse();
});

it('renders the form of a record saved through the multipart path', function () {
    msvMultipartUpdate($this, $this->site->id, [
        'effects' => '{"blur":true,"grain":true}',
    ], ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])->assertStatus(200);

    $response = $this->getJson("/martis/api/resources/m-s-v-site-models/{$this->site->id}?context=update");

    $response->assertStatus(200);
    expect($response->json('data.effects'))->toBe(['blur' => true, 'grain' => true])
        ->and($response->json('data.states'))->toBe(['available', 'reserved'])
        ->and($response->json('data.sections.0.fields.key'))->toBe('hero');
});

// ---------------------------------------------------------------------------
// The decoding step itself
// ---------------------------------------------------------------------------

it('declares hasStructuredValue() on exactly the fields whose form value is a list or a map', function () {
    expect(Repeater::make('r')->hasStructuredValue())->toBeTrue()
        ->and(MultiSelect::make('m')->hasStructuredValue())->toBeTrue()
        ->and(BooleanGroup::make('b')->hasStructuredValue())->toBeTrue()
        ->and(KeyValue::make('k')->hasStructuredValue())->toBeTrue()
        ->and(Tag::make('tags', 'tags')->hasStructuredValue())->toBeTrue()
        ->and(MorphTo::make('owner', 'owner')->hasStructuredValue())->toBeTrue()
        ->and(Sparkline::make('s')->hasStructuredValue())->toBeTrue()
        ->and(Text::make('t')->hasStructuredValue())->toBeFalse()
        ->and(Boolean::make('p')->hasStructuredValue())->toBeFalse()
        ->and(Image::make('i')->hasStructuredValue())->toBeFalse();
});

it('decodes only JSON-string values of structured fields and leaves everything else untouched', function () {
    $decoder = new class
    {
        use DecodesStructuredValues;

        /**
         * @param  list<Field>  $fields
         * @return list<string>
         */
        public function run(Request $request, array $fields): array
        {
            return $this->decodeStructuredValues($request, $fields);
        }
    };

    $request = Request::create('/x', 'POST', [
        'tags' => '[1,2]',
        'owner' => '{"resourceType":"users","id":7}',
        'points' => '[1,2,3]',
        'meta' => '{"a":"1"}',
        'already' => ['k' => 'v'],
        'broken' => '[object Object]',
        'scalar' => '"just a string"',
        'cleared' => '',
        'body' => '{"looks":"like json"}',
        'name' => 'plain',
    ]);

    $undecodable = $decoder->run($request, [
        Tag::make('tags', 'tags'),
        MorphTo::make('owner', 'owner'),
        Sparkline::make('points'),
        KeyValue::make('meta'),
        KeyValue::make('already'),
        MultiSelect::make('broken'),
        Repeater::make('scalar'),
        BooleanGroup::make('cleared'),
        MultiSelect::make('missing'),
        Text::make('body'),
        Text::make('name'),
    ]);

    // Only a non-empty string that is not JSON for a list or map is
    // reported; the empty string (null on the multipart path), an absent
    // value and non-structured fields are not.
    expect($undecodable)->toBe(['broken', 'scalar']);

    expect($request->input('tags'))->toBe([1, 2])
        ->and($request->input('owner'))->toBe(['resourceType' => 'users', 'id' => 7])
        ->and($request->input('points'))->toBe([1, 2, 3])
        ->and($request->input('meta'))->toBe(['a' => '1'])
        ->and($request->input('already'))->toBe(['k' => 'v'])
        ->and($request->input('broken'))->toBe('[object Object]')
        ->and($request->input('scalar'))->toBe('"just a string"')
        ->and($request->input('cleared'))->toBe('')
        ->and($request->input('body'))->toBe('{"looks":"like json"}')
        ->and($request->input('name'))->toBe('plain');
});

it('BooleanGroup::fill() decodes a JSON string into the map before writing', function () {
    $model = new MSVSiteModel;

    BooleanGroup::make('effects')->options(['blur' => 'Blur', 'grain' => 'Grain'])->fill($model, '{"blur":true,"grain":false}');

    expect($model->effects)->toBe(['blur' => true, 'grain' => false])
        ->and($model->getAttributes()['effects'])->toBe('{"blur":true,"grain":false}');
});

it('BooleanGroup::fill() writes an array as received', function () {
    $model = new MSVSiteModel;

    BooleanGroup::make('effects')->fill($model, ['blur' => false]);

    expect($model->effects)->toBe(['blur' => false]);
});
