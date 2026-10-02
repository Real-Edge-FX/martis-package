<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BooleanGroup;
use Martis\Fields\Gravatar;
use Martis\Fields\Icon;
use Martis\Fields\KeyValue;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// The resource endpoints write each field through its fill(), so the
// boundaries a field declares hold for a request that never touches the
// form (F010, F024, F052, F107): the flags a BooleanGroup offers, a
// readonly() closure on an Icon, the https URL of a Gravatar and the key
// set a KeyValue fixes.

class FwbItem extends Model
{
    protected $table = 'fwb_items';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['permissions' => 'array', 'meta' => 'array'];
}

class FwbItemResource extends Resource
{
    public static function model(): string
    {
        return FwbItem::class;
    }

    public static function uriKey(): string
    {
        return 'fwb-items';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->nullable(),
            // The editor is offered `view` and `edit`; `admin` is for administrators.
            BooleanGroup::make('permissions')->options(fn () => ['view' => 'View', 'edit' => 'Edit'])->nullable(),
            KeyValue::make('meta')->default(['mon' => '9-17', 'tue' => '9-17'])->disableEditingKeys()->disableAddingRows()->disableDeletingRows()->nullable(),
            Icon::make('icon')->stored()->readonly(fn (Request $request) => $request->header('X-Lock') === '1')->nullable(),
            Gravatar::make('avatar_url')->fromUrl()->showOnForms()->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('fwb_items');
    Schema::create('fwb_items', function ($t) {
        $t->id();
        $t->string('title')->nullable();
        $t->json('permissions')->nullable();
        $t->json('meta')->nullable();
        $t->string('icon')->nullable();
        $t->string('avatar_url')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(FwbItemResource::class);
});

afterEach(function () {
    Schema::dropIfExists('fwb_items');
    app(ResourceRegistry::class)->flush();
});

function fwbStored(): array
{
    return FwbItem::query()->latest('id')->firstOrFail()->only(['permissions', 'meta', 'icon', 'avatar_url']);
}

it('stores only the flags the field offers, on create and on update', function () {
    $response = $this->postJson('/martis/api/resources/fwb-items', ['permissions' => ['view' => true, 'admin' => true]]);
    $response->assertCreated();

    expect(fwbStored()['permissions'])->toBe(['view' => true, 'edit' => false]);

    $id = $response->json('data.id');
    // An administrator set `admin`; an editor's save neither flips nor erases it.
    FwbItem::query()->find($id)->update(['permissions' => ['view' => false, 'edit' => false, 'admin' => true]]);

    $this->putJson("/martis/api/resources/fwb-items/{$id}", ['permissions' => ['view' => true, 'edit' => true, 'admin' => false, 'root' => true]])->assertOk();

    expect(FwbItem::query()->find($id)->permissions)->toBe(['view' => true, 'edit' => true, 'admin' => true]);
});

it('stores only the flags the field offers when the map arrives as JSON text', function () {
    $this->post('/martis/api/resources/fwb-items', ['permissions' => '{"view":true,"admin":true}'], ['Accept' => 'application/json'])->assertCreated();

    expect(fwbStored()['permissions'])->toBe(['view' => true, 'edit' => false]);
});

it('holds a KeyValue with fixed keys to its default keys on create and its stored keys on update', function () {
    $response = $this->postJson('/martis/api/resources/fwb-items', ['meta' => ['mon' => '10-16', 'sun' => 'open']]);
    $response->assertCreated();

    expect(fwbStored()['meta'])->toBe(['mon' => '10-16', 'tue' => '9-17']);

    $id = $response->json('data.id');
    $this->putJson("/martis/api/resources/fwb-items/{$id}", ['meta' => ['tue' => 'closed', 'wed' => 'new', 'renamed' => 'x']])->assertOk();

    expect(FwbItem::query()->find($id)->meta)->toBe(['mon' => '10-16', 'tue' => 'closed']);
});

it('does not write an Icon while its readonly closure holds it', function () {
    $id = FwbItem::query()->create(['icon' => 'star'])->id;

    $this->putJson("/martis/api/resources/fwb-items/{$id}", ['icon' => 'crown'], ['X-Lock' => '1'])->assertOk();
    expect(FwbItem::query()->find($id)->icon)->toBe('star');

    $this->putJson("/martis/api/resources/fwb-items/{$id}", ['icon' => 'crown'])->assertOk();
    expect(FwbItem::query()->find($id)->icon)->toBe('crown');
});

it('answers 422 to a Gravatar URL that is not https', function (string $url) {
    $this->postJson('/martis/api/resources/fwb-items', ['avatar_url' => $url])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'avatar_url');

    expect(FwbItem::query()->count())->toBe(0);
})->with(['http://example.com/a.png', 'javascript:alert(1)', 'data:image/png;base64,AAAA']);

it('stores an https Gravatar URL', function () {
    $this->postJson('/martis/api/resources/fwb-items', ['avatar_url' => 'https://cdn.example.com/a.png'])->assertCreated();

    expect(fwbStored()['avatar_url'])->toBe('https://cdn.example.com/a.png');
});
