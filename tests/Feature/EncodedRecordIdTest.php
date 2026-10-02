<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// The SPA builds every API path from encoded values (resources/js/lib/apiPath.ts):
// a record keyed `../users/5` is requested as
// `/api/resources/posts/..%252Fusers%252F5`, because Laravel decodes the path
// (`rawurldecode`) before it matches a route, so a plain `%2F` would be a
// slash again (`1%2Fforce` reaches the force-delete route of record 1), and
// the browser removes the dot segments of a raw one. Such an id must find no
// route and no record: it must never be read as a path to another endpoint or
// another record. This pins the server half of the client-side path traversal
// fix (F007, F011, F012, F015, F016, F066).
// ---------------------------------------------------------------------------

class EncodedIdModel extends Model
{
    protected $table = 'martis_test_encoded_ids';

    protected $fillable = ['title'];
}

class EncodedIdResource extends Resource
{
    public static function model(): string
    {
        return EncodedIdModel::class;
    }

    public static function uriKey(): string
    {
        return 'encoded-ids';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_encoded_ids');
    Schema::create('martis_test_encoded_ids', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(EncodedIdResource::class);

    $this->record = EncodedIdModel::create(['title' => 'Original']);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_encoded_ids');
});

it('finds the record by its plain id', function () {
    $this->getJson("/martis/api/resources/encoded-ids/{$this->record->id}")->assertOk();
});

it('answers 404 for an id that is an encoded path to another record', function (string $id) {
    $this->getJson("/martis/api/resources/encoded-ids/{$id}")->assertNotFound();
})->with([
    'parent traversal' => fn () => '..%2Fencoded-ids%2F1',
    'deep traversal' => fn () => '..%2F..%2Fencoded-ids%2F1',
    'encoded dots' => fn () => '%2e%2e%2Fencoded-ids%2F1',
    'with a query string' => fn () => '..%2Fencoded-ids%2F1%3Ftitle%3Dx',
    'a backslash' => fn () => '..%5Cencoded-ids%5C1',
    'as the SPA spells a slash' => fn () => '..%252Fencoded-ids%252F1',
]);

it('answers 404 for a key with a slash, as the SPA spells it, instead of reaching a route of another record', function (string $suffix) {
    $id = $this->record->id;
    $path = "/martis/api/resources/encoded-ids/{$id}%252F{$suffix}";

    $this->getJson($path)->assertNotFound();
    $this->deleteJson($path)->assertNotFound();
    $this->putJson($path, ['title' => 'Changed'])->assertNotFound();

    expect(EncodedIdModel::query()->count())->toBe(1);
    expect($this->record->fresh()->title)->toBe('Original');
})->with(['force', 'restore', 'peek', 'replicate']);

it('does not delete, update or restore a record through an encoded path', function () {
    $encoded = '..%2Fencoded-ids%2F'.$this->record->id;

    $this->deleteJson("/martis/api/resources/encoded-ids/{$encoded}")->assertNotFound();
    $this->putJson("/martis/api/resources/encoded-ids/{$encoded}", ['title' => 'Changed'])->assertNotFound();
    $this->putJson("/martis/api/resources/encoded-ids/{$encoded}/restore")->assertNotFound();
    $this->deleteJson("/martis/api/resources/encoded-ids/{$encoded}/force")->assertNotFound();

    expect(EncodedIdModel::query()->count())->toBe(1);
    expect($this->record->fresh()->title)->toBe('Original');
});

it('does not read an encoded dot segment as the resource', function () {
    $this->getJson('/martis/api/resources/..%2Fencoded-ids/'.$this->record->id)->assertNotFound();
});

it('does not take an unencoded dot segment for another endpoint', function () {
    // What a client that did not encode would have sent, if nothing between it
    // and the server had resolved the dot segments.
    $this->deleteJson("/martis/api/resources/encoded-ids/../encoded-ids/{$this->record->id}")->assertNotFound();

    expect(EncodedIdModel::query()->count())->toBe(1);
});
