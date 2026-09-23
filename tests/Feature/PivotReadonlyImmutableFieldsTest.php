<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// readonly() and immutable() on the pivot fields of a BelongsToMany /
// MorphToMany.
//
// The attach and the pivot update write the pivot row through Eloquent, not
// through Field::fill(), so they apply the write rules of a record field
// themselves: a readonly pivot field never takes its value from the request
// (the attach stores its default() when it has one), and an immutable pivot
// field is written on attach and skipped on the pivot update. The rules a
// pivot field shares with a record field also run against the resource
// endpoint, the reference the pivot endpoints match.
//
// Both pivot endpoints used to write every pivot value the request sent.
// ===========================================================================

class PRIParentModel extends Model
{
    protected $table = 'pri_parents';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PRITagModel::class, 'pri_parent_tag', 'parent_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(PRITagModel::class, 'taggable', 'pri_taggables', null, 'tag_id');
    }
}

class PRITagModel extends Model
{
    protected $table = 'pri_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class PRIRecordModel extends Model
{
    protected $table = 'pri_records';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * The fields of every write, on the pivot and on the reference record: an
 * immutable code, a readonly note, a readonly stamp with a default and a
 * plain title.
 *
 * @return list<Text>
 */
function priFields(): array
{
    return [
        Text::make('code')->immutable()->required(),
        Text::make('note')->readonly(),
        Text::make('stamp')->readonly()->default('server'),
        Text::make('title'),
    ];
}

class PRIParentResource extends Resource
{
    public static function model(): string
    {
        return PRIParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'pri-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('pri-tags')->fields(fn () => priFields()),
            MorphToMany::make('Labels', 'labels')->relatedResource('pri-tags')->fields(fn () => priFields()),
        ];
    }
}

class PRITagResource extends Resource
{
    public static function model(): string
    {
        return PRITagModel::class;
    }

    public static function uriKey(): string
    {
        return 'pri-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class PRIRecordResource extends Resource
{
    public static function model(): string
    {
        return PRIRecordModel::class;
    }

    public static function uriKey(): string
    {
        return 'pri-records';
    }

    public function fields(Request $request): array
    {
        return priFields();
    }
}

function priDropTables(): void
{
    foreach (['pri_taggables', 'pri_parent_tag', 'pri_records', 'pri_tags', 'pri_parents'] as $table) {
        Schema::dropIfExists($table);
    }
}

/**
 * The relationship method behind a pivot endpoint.
 */
function priRelation(string $endpoint): string
{
    return $endpoint === 'belongs-to-many' ? 'tags' : 'labels';
}

/**
 * The table an endpoint writes: the records of the reference resource, or
 * the pivot rows of a relationship.
 */
function priTable(string $endpoint): string
{
    return match ($endpoint) {
        'resource' => 'pri_records',
        'belongs-to-many' => 'pri_parent_tag',
        'morph-to-many' => 'pri_taggables',
    };
}

/**
 * The single row an endpoint wrote.
 *
 * @return array<string, mixed>
 */
function priStoredRow(string $endpoint): array
{
    return (array) DB::table(priTable($endpoint))->sole(['code', 'note', 'stamp', 'title']);
}

/**
 * Return the URL and the body that create a record through the resource
 * endpoint, or attach one tag (several with `$batch`) through a pivot
 * endpoint.
 *
 * @param  array<string, mixed>  $payload
 * @return array{0: string, 1: array<string, mixed>}
 */
function priCreateRequest(string $endpoint, array $payload, bool $batch = false): array
{
    if ($endpoint === 'resource') {
        return ['/martis/api/resources/pri-records', $payload];
    }

    $parent = PRIParentModel::create(['name' => 'Parent']);
    $url = "/martis/api/resources/pri-parents/{$parent->id}/{$endpoint}/".priRelation($endpoint).'/attach';

    $related = $batch
        ? ['related_ids' => [PRITagModel::create(['name' => 'One'])->id, PRITagModel::create(['name' => 'Two'])->id]]
        : ['related_id' => PRITagModel::create(['name' => 'Tag'])->id];

    return [$url, $related + $payload];
}

/**
 * Store a record, or attach a tag with its pivot values, and return the URL
 * that updates it.
 *
 * @param  array<string, mixed>  $values
 */
function priUpdateUrl(string $endpoint, array $values): string
{
    if ($endpoint === 'resource') {
        return '/martis/api/resources/pri-records/'.PRIRecordModel::create($values)->id;
    }

    $parent = PRIParentModel::create(['name' => 'Parent']);
    $tag = PRITagModel::create(['name' => 'Tag']);
    $relation = priRelation($endpoint);
    $parent->{$relation}()->attach($tag->id, $values);

    return "/martis/api/resources/pri-parents/{$parent->id}/{$endpoint}/{$relation}/{$tag->id}/pivot";
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    priDropTables();

    $columns = function ($table): void {
        foreach (['code', 'note', 'stamp', 'title'] as $column) {
            $table->string($column)->nullable();
        }
    };

    Schema::create('pri_parents', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('pri_tags', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('pri_records', function ($table) use ($columns) {
        $table->id();
        $columns($table);
    });
    Schema::create('pri_parent_tag', function ($table) use ($columns) {
        $table->unsignedBigInteger('parent_id');
        $table->unsignedBigInteger('tag_id');
        $columns($table);
    });
    Schema::create('pri_taggables', function ($table) use ($columns) {
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $columns($table);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PRIParentResource::class);
    $registry->register(PRITagResource::class);
    $registry->register(PRIRecordResource::class);
});

afterEach(function () {
    priDropTables();
});

$endpoints = ['resource', 'belongs-to-many', 'morph-to-many'];
$pivotEndpoints = ['belongs-to-many', 'morph-to-many'];

it('stores an immutable field on create and on attach', function (string $endpoint) {
    $this->postJson(...priCreateRequest($endpoint, ['code' => 'C-1', 'title' => 'First']))->assertStatus(201);

    expect(priStoredRow($endpoint))->toMatchArray(['code' => 'C-1', 'title' => 'First']);
})->with($endpoints);

it('skips an immutable field on update and still writes the other fields', function (string $endpoint) {
    $url = priUpdateUrl($endpoint, ['code' => 'frozen', 'title' => 'Before']);

    $this->putJson($url, ['code' => 'tampered', 'title' => 'After'])->assertStatus(200);

    expect(priStoredRow($endpoint))->toMatchArray(['code' => 'frozen', 'title' => 'After']);
})->with($endpoints);

it('ignores the value a readonly field receives on create and on attach', function (string $endpoint) {
    $this->postJson(...priCreateRequest($endpoint, ['code' => 'C-1', 'note' => 'forged', 'title' => 'First']))
        ->assertStatus(201);

    expect(priStoredRow($endpoint))->toMatchArray(['code' => 'C-1', 'note' => null, 'title' => 'First']);
})->with($endpoints);

it('skips a readonly field on update and still writes the other fields', function (string $endpoint) {
    $url = priUpdateUrl($endpoint, ['code' => 'C-1', 'note' => 'original', 'stamp' => 'server', 'title' => 'Before']);

    $this->putJson($url, ['note' => 'forged', 'stamp' => 'forged', 'title' => 'After'])->assertStatus(200);

    expect(priStoredRow($endpoint))->toMatchArray(['note' => 'original', 'stamp' => 'server', 'title' => 'After']);
})->with($endpoints);

it('stores the default of a readonly pivot field on attach, whatever the request sends', function (string $endpoint, array $stamp) {
    $this->postJson(...priCreateRequest($endpoint, ['code' => 'C-1'] + $stamp))->assertStatus(201);

    expect(priStoredRow($endpoint))->toMatchArray(['stamp' => 'server']);
})->with($pivotEndpoints)->with([
    'a forged value' => [['stamp' => 'forged']],
    'no value' => [[]],
]);

it('applies the pivot write rules to every record of a batch attach', function (string $endpoint) {
    $this->postJson(...priCreateRequest($endpoint, ['code' => 'C-1', 'note' => 'forged', 'stamp' => 'forged', 'title' => 'Shared'], batch: true))
        ->assertStatus(201)
        ->assertJsonPath('data.count', 2);

    $rows = DB::table(priTable($endpoint))->orderBy('tag_id')->get(['code', 'note', 'stamp', 'title'])
        ->map(fn (object $row): array => (array) $row)
        ->all();
    $expected = ['code' => 'C-1', 'note' => null, 'stamp' => 'server', 'title' => 'Shared'];

    expect($rows)->toBe([$expected, $expected]);
})->with($pivotEndpoints);

it('returns only the pivot values it wrote', function (string $endpoint) {
    $url = priUpdateUrl($endpoint, ['code' => 'frozen', 'title' => 'Before']);

    $this->putJson($url, ['code' => 'tampered', 'note' => 'forged', 'title' => 'After'])
        ->assertStatus(200)
        ->assertJsonPath('data.pivot', ['title' => 'After']);
})->with($pivotEndpoints);

it('answers a pivot update with nothing to write with a 200 and leaves the row as it was', function (string $endpoint, array $payload) {
    $stored = ['code' => 'frozen', 'note' => 'original', 'stamp' => 'server', 'title' => 'Kept'];
    $url = priUpdateUrl($endpoint, $stored);

    $this->putJson($url, $payload)
        ->assertStatus(200)
        ->assertJsonPath('data.pivot', []);

    expect(priStoredRow($endpoint))->toBe($stored);
})->with($pivotEndpoints)->with([
    'only readonly and immutable values' => [['code' => 'tampered', 'note' => 'forged', 'stamp' => 'forged']],
    'an empty body' => [[]],
]);
