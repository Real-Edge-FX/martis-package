<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BooleanGroup;
use Martis\Fields\KeyValue;
use Martis\Fields\MultiSelect;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A number or a boolean sent for a structured field fails validation
// (v1.38.0).
//
// The rejection of unstructured values only looked at strings, so a JSON
// request that sent `{"meta": 5, "labels": true}` passed validation and the
// built-in fills turned the scalar into an empty value: the KeyValue map,
// the MultiSelect list, the Repeater rows and the BooleanGroup were wiped.
// Any value that is not a list or a map now answers 422 for a field that
// rejects unstructured values; null still clears it.
//
// A Repeater with a fillUsing() callback is left to the callback, as every
// other field is: Repeater::fill() used to ignore fillUsing() and computed()
// and always write the rows.
// ===========================================================================

class SSVSection extends Repeatable
{
    public function fields(Request $request): array
    {
        return [Text::make('key', 'Key')];
    }
}

class SSVDocModel extends Model
{
    protected $table = 'ssv_docs';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'meta' => 'array',
        'labels' => 'array',
        'sections' => 'array',
        'effects' => 'array',
    ];
}

class SSVDocResource extends Resource
{
    public static function model(): string
    {
        return SSVDocModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            KeyValue::make('meta'),
            MultiSelect::make('labels')->options(['x' => 'X', 'y' => 'Y']),
            Repeater::make('sections')->asJson()->repeatables([SSVSection::make()]),
            BooleanGroup::make('effects')->options(['blur' => 'Blur', 'grain' => 'Grain']),
        ];
    }
}

class SSVNoteModel extends Model
{
    protected $table = 'ssv_notes';

    protected $guarded = [];

    public $timestamps = false;
}

class SSVNoteResource extends Resource
{
    public static function model(): string
    {
        return SSVNoteModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            Repeater::make('sections')->asJson()->repeatables([SSVSection::make()])
                ->fillUsing(function (Model $model, mixed $value, string $attribute): void {
                    $model->setAttribute('summary', is_array($value) ? count($value).' section(s)' : 'raw:'.json_encode($value));
                }),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('ssv_docs');
    Schema::dropIfExists('ssv_notes');
    Schema::create('ssv_docs', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->json('meta')->nullable();
        $table->json('labels')->nullable();
        $table->json('sections')->nullable();
        $table->json('effects')->nullable();
    });
    Schema::create('ssv_notes', function ($table) {
        $table->id();
        $table->string('title')->nullable();
        $table->json('sections')->nullable();
        $table->string('summary')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SSVDocResource::class);
    $registry->register(SSVNoteResource::class);

    $this->doc = SSVDocModel::create([
        'title' => 'Doc',
        'meta' => ['owner' => 'ana'],
        'labels' => ['x'],
        'sections' => [['id' => 's1', 'type' => 's-s-v-section', 'fields' => ['key' => 'hero']]],
        'effects' => ['blur' => true, 'grain' => false],
    ]);
});

afterEach(function () {
    Schema::dropIfExists('ssv_docs');
    Schema::dropIfExists('ssv_notes');
});

it('rejects a number or a boolean sent for a structured field and keeps what is stored', function (string $attribute, mixed $value) {
    $response = $this->putJson("/martis/api/resources/s-s-v-doc-models/{$this->doc->id}", [
        'title' => 'Changed',
        $attribute => $value,
    ]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toBe([$attribute]);

    $fresh = $this->doc->fresh();
    expect($fresh->title)->toBe('Doc')
        ->and($fresh->meta)->toBe(['owner' => 'ana'])
        ->and($fresh->labels)->toBe(['x'])
        ->and($fresh->sections)->toHaveCount(1)
        ->and($fresh->effects)->toBe(['blur' => true, 'grain' => false]);
})->with([
    'KeyValue, integer' => ['meta', 5],
    'MultiSelect, boolean' => ['labels', true],
    'Repeater, float' => ['sections', 1.5],
    'BooleanGroup, false' => ['effects', false],
    'KeyValue, JSON scalar string' => ['meta', '5'],
]);

it('still clears a structured field sent as null', function () {
    $this->putJson("/martis/api/resources/s-s-v-doc-models/{$this->doc->id}", [
        'meta' => null,
        'labels' => null,
    ])->assertOk();

    $fresh = $this->doc->fresh();
    expect($fresh->meta)->toBeNull()
        ->and($fresh->labels)->toBeNull();
});

it('leaves a Repeater with a fillUsing() callback to the callback', function () {
    $note = SSVNoteModel::create(['title' => 'Note', 'sections' => json_encode([['id' => 's1']])]);

    // A value the built-in fill would reject is the callback's to handle.
    $this->putJson("/martis/api/resources/s-s-v-note-models/{$note->id}", ['sections' => 7])->assertOk();

    $fresh = $note->fresh();
    expect($fresh->summary)->toBe('raw:7')
        ->and(json_decode((string) $fresh->sections, true))->toBe([['id' => 's1']]);

    $this->putJson("/martis/api/resources/s-s-v-note-models/{$note->id}", [
        'sections' => [['type' => 's-s-v-section', 'fields' => ['key' => 'a']], ['type' => 's-s-v-section', 'fields' => ['key' => 'b']]],
    ])->assertOk();

    expect($note->fresh()->summary)->toBe('2 section(s)')
        ->and(json_decode((string) $note->fresh()->sections, true))->toBe([['id' => 's1']]);
});

it('writes nothing for a computed Repeater', function () {
    $model = new SSVDocModel;

    Repeater::make('sections')->asJson()->computed(fn () => [])->fill($model, [['type' => 'x', 'fields' => []]]);

    expect($model->getAttributes())->not->toHaveKey('sections');
});
