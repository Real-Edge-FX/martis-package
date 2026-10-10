<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsTo;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// v2.10.0: a BelongsTo submitted as the `{ id, title }` map the form holds
// (a create opened from a relationship panel pre-fills the parent that way)
// reaches the field's own rules, and the fill, as the id. A consumer rule
// such as `Rule::exists('btm_contacts', 'id')` otherwise receives an array
// (a multi-value whereIn: a 500 on PostgreSQL, a false 422 elsewhere).
// A map whose id is not an int or a string stays untouched, so the
// Relatable rule still refuses it.

class BtmContact extends Model
{
    protected $table = 'btm_contacts';

    protected $guarded = [];

    public $timestamps = false;

    public function notes(): EloquentHasMany
    {
        return $this->hasMany(BtmNote::class, 'contact_id');
    }
}

class BtmNote extends Model
{
    protected $table = 'btm_notes';

    protected $guarded = [];

    public $timestamps = false;

    public function contact(): EloquentBelongsTo
    {
        return $this->belongsTo(BtmContact::class, 'contact_id');
    }
}

class BtmAssign extends Action
{
    public ?string $name = 'Assign';

    public static mixed $received = null;

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        self::$received = $fields->contact_id;

        return ActionResponse::message('Done.');
    }

    public function fields(Request $request): array
    {
        return [BelongsTo::make('contact', 'Contact')->relatedResource('btm-contacts')->rules(['required', Rule::exists('btm_contacts', 'id')])];
    }
}

class BtmContactResource extends Resource
{
    public static function model(): string
    {
        return BtmContact::class;
    }

    public static function uriKey(): string
    {
        return 'btm-contacts';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Notes', 'notes')->relatedResource('btm-notes'),
        ];
    }

    public function actions(Request $request): array
    {
        return [BtmAssign::make()];
    }
}

class BtmNoteResource extends Resource
{
    public static function model(): string
    {
        return BtmNote::class;
    }

    public static function uriKey(): string
    {
        return 'btm-notes';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('contact', 'Contact')->relatedResource('btm-contacts')->rules(['required', Rule::exists('btm_contacts', 'id')]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('btm_notes');
    Schema::dropIfExists('btm_contacts');
    Schema::create('btm_contacts', function ($t) {
        $t->id();
        $t->string('name')->nullable();
    });
    Schema::create('btm_notes', function ($t) {
        $t->id();
        $t->string('title')->nullable();
        $t->unsignedBigInteger('contact_id')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(BtmContactResource::class);
    $registry->register(BtmNoteResource::class);

    $this->contact = BtmContact::create(['name' => 'Ana']);
});

afterEach(function () {
    Schema::dropIfExists('btm_notes');
    Schema::dropIfExists('btm_contacts');
    app(ResourceRegistry::class)->flush();
});

it('stores the id of a {id, title} map on the resource create', function () {
    $this->postJson('/martis/api/resources/btm-notes', ['title' => 'A', 'contact_id' => ['id' => $this->contact->id, 'title' => 'Ana']])
        ->assertCreated();

    expect(BtmNote::query()->value('contact_id'))->toBe($this->contact->id);
});

it('stores the id of a {id, title} map on the has-many create', function () {
    $this->postJson("/martis/api/resources/btm-contacts/{$this->contact->id}/has-many/notes", ['title' => 'A', 'contact_id' => ['id' => (string) $this->contact->id, 'title' => 'Ana']])
        ->assertCreated();

    expect(BtmNote::query()->value('contact_id'))->toBe($this->contact->id);
});

it('stores the id of a {id, title} map on the resource update', function () {
    $other = BtmContact::create(['name' => 'Bia']);
    $note = BtmNote::create(['title' => 'A', 'contact_id' => $this->contact->id]);

    $this->putJson("/martis/api/resources/btm-notes/{$note->id}", ['contact_id' => ['id' => $other->id, 'title' => 'Bia']])
        ->assertOk();

    expect($note->fresh()->contact_id)->toBe($other->id);
});

it('stores the id of a {id, title} map sent as multipart JSON text', function () {
    $this->post('/martis/api/resources/btm-notes', ['title' => 'A', 'contact_id' => json_encode(['id' => $this->contact->id, 'title' => 'Ana'])], ['Accept' => 'application/json'])
        ->assertCreated();

    expect(BtmNote::query()->value('contact_id'))->toBe($this->contact->id);
});

it('still runs the consumer rule on the id a map names', function () {
    $response = $this->postJson('/martis/api/resources/btm-notes', ['title' => 'A', 'contact_id' => ['id' => 9999, 'title' => 'Ghost']]);

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'contact_id');
    expect(BtmNote::query()->count())->toBe(0);
});

it('refuses a map whose id is not an int or a string', function (mixed $id) {
    $this->postJson('/martis/api/resources/btm-notes', ['title' => 'A', 'contact_id' => ['id' => $id]])
        ->assertStatus(422);

    expect(BtmNote::query()->count())->toBe(0);
})->with([
    'a boolean' => [true],
    'a float' => [1.5],
    'a list' => [[1]],
    'a nested map' => [['id' => 1]],
]);

it('never writes a malformed id through the has-many store, which sets the parent key itself', function () {
    $other = BtmContact::create(['name' => 'Bia']);

    $this->postJson("/martis/api/resources/btm-contacts/{$other->id}/has-many/notes", ['title' => 'A', 'contact_id' => ['id' => true]]);

    expect(BtmNote::query()->where('contact_id', $this->contact->id)->count())->toBe(0);
});

it('a map that names no id fails the required rule instead of passing it', function () {
    $this->postJson('/martis/api/resources/btm-notes', ['title' => 'A', 'contact_id' => ['title' => 'Ana']])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'contact_id');
});

it('hands an Action the id of a {id, title} map, after the consumer rule ran on it', function () {
    BtmAssign::$received = null;

    $this->postJson('/martis/api/resources/btm-contacts/actions/btm-assign', [
        'resources' => [$this->contact->id],
        'fields' => ['contact_id' => ['id' => $this->contact->id, 'title' => 'Ana']],
    ])->assertOk();

    expect(BtmAssign::$received)->toBe($this->contact->id);
});

it('refuses an Action field map whose id is malformed', function () {
    $this->postJson('/martis/api/resources/btm-contacts/actions/btm-assign', [
        'resources' => [$this->contact->id],
        'fields' => ['contact_id' => ['id' => true]],
    ])->assertStatus(422);
});
