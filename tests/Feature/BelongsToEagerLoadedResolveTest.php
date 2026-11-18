<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as BelongsToRelation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class BtlCountry extends Model
{
    protected $table = 'btl_countries';

    public $timestamps = false;

    protected $fillable = ['name'];
}

class BtlAuthor extends Model
{
    protected $table = 'btl_authors';

    public $timestamps = false;

    protected $fillable = ['name', 'nickname', 'country_id'];

    public function country(): BelongsToRelation
    {
        return $this->belongsTo(BtlCountry::class, 'country_id');
    }
}

/** The same table, with soft deletes. */
class BtlArchivableAuthor extends BtlAuthor
{
    use SoftDeletes;
}

class BtlPost extends Model
{
    protected $table = 'btl_posts';

    public $timestamps = false;

    protected $fillable = ['title', 'author_id'];

    public function author(): BelongsToRelation
    {
        return $this->belongsTo(BtlAuthor::class, 'author_id');
    }
}

class BtlArchivedPost extends Model
{
    protected $table = 'btl_posts';

    public $timestamps = false;

    protected $fillable = ['title', 'author_id'];

    public function author(): BelongsToRelation
    {
        return $this->belongsTo(BtlArchivableAuthor::class, 'author_id');
    }
}

/** A title that reads a further relation: a second query per row when the author is re-fetched. */
class BtlAuthorResource extends Resource
{
    public static function model(): string
    {
        return BtlAuthor::class;
    }

    public static function uriKey(): string
    {
        return 'btl-authors';
    }

    public function title(): string
    {
        return $this->model->name.' ('.$this->model->country?->name.')';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    Schema::create('btl_countries', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('btl_authors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('nickname')->nullable();
        $table->foreignId('country_id')->nullable();
        $table->softDeletes();
    });

    Schema::create('btl_posts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->foreignId('author_id')->nullable();
    });

    app(ResourceRegistry::class)->register(BtlAuthorResource::class);

    $country = BtlCountry::create(['name' => 'Portugal']);

    foreach (['Ana', 'Bruno', 'Carla'] as $i => $name) {
        $author = BtlAuthor::create(['name' => $name, 'nickname' => strtolower($name).'-nick', 'country_id' => $country->id]);
        BtlPost::create(['title' => 'Post '.($i + 1), 'author_id' => $author->id]);
    }
});

afterEach(function () {
    Schema::dropIfExists('btl_posts');
    Schema::dropIfExists('btl_authors');
    Schema::dropIfExists('btl_countries');

    app(ResourceRegistry::class)->flush();
});

/**
 * Resolve the field for every post and report the queries it cost.
 *
 * @param  Collection<int, Model>  $posts
 * @return array{0: list<mixed>, 1: int}
 */
function btlResolveAll(BelongsTo $field, $posts): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $resolved = $posts->map(fn (Model $post) => $field->resolve($post))->all();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$resolved, $count];
}

// ---------------------------------------------------------------------------
// 1. No extra query per row with the relation eager-loaded
// ---------------------------------------------------------------------------

it('issues no query per row when the relation, and the relation its title reads, are eager-loaded', function (string $postClass) {
    $field = BelongsTo::make('author')->relatedResource('btl-authors');
    $posts = $postClass::query()->with('author.country')->get();

    [$resolved, $queries] = btlResolveAll($field, $posts);

    expect($queries)->toBe(0)
        ->and(collect($resolved)->pluck('title')->all())->toBe(['Ana (Portugal)', 'Bruno (Portugal)', 'Carla (Portugal)']);
})->with([
    // A soft-deleting target used to be re-fetched with withTrashed() on every row.
    'soft-deleting target' => [BtlArchivedPost::class],
    'plain target' => [BtlPost::class],
]);

it('costs queries per row without the eager load (the guard is not vacuous)', function (string $postClass) {
    $field = BelongsTo::make('author')->relatedResource('btl-authors');
    $posts = $postClass::query()->get();

    [, $queries] = btlResolveAll($field, $posts);

    expect($queries)->toBeGreaterThanOrEqual(3);
})->with([
    'soft-deleting target' => [BtlArchivedPost::class],
    'plain target' => [BtlPost::class],
]);

it('reuses the very instance the eager load holds, also for a soft-deleting target', function () {
    $field = BelongsTo::make('author')->titleAttribute('name');
    $post = BtlArchivedPost::query()->with('author')->first();

    // Mutate the loaded instance in memory: a re-query would read the stored name.
    $post->author->name = 'In memory';

    expect($field->resolve($post)['title'])->toBe('In memory');
});

// ---------------------------------------------------------------------------
// 2. The same title with and without the eager load
// ---------------------------------------------------------------------------

it('resolves the same value with and without the eager load', function (bool $withTitleResource) {
    $postClass = BtlArchivedPost::class;
    $make = fn () => $withTitleResource
        ? BelongsTo::make('author')->relatedResource('btl-authors')
        : BelongsTo::make('author')->titleAttribute('name');

    $lazy = $postClass::query()->get()->map(fn (Model $post) => $make()->resolve($post))->all();
    $eager = $postClass::query()->with('author')->get()->map(fn (Model $post) => $make()->resolve($post))->all();

    expect($eager)->toBe($lazy)
        ->and($eager[0])->toHaveKeys(['id', 'title'])
        ->and($eager[0]['title'])->not->toBeNull();
})->with([
    'title from the related resource' => [true],
    'title from the field attribute' => [false],
]);

// ---------------------------------------------------------------------------
// 3. A soft-deleted target still resolves (the eager load leaves it null)
// ---------------------------------------------------------------------------

it('resolves the title of a soft-deleted target when the relation was eager-loaded', function () {
    $field = BelongsTo::make('author')->titleAttribute('name');

    BtlArchivableAuthor::query()->whereKey(2)->first()->delete();

    $posts = BtlArchivedPost::query()->with('author')->orderBy('id')->get();

    // The eager load leaves the soft-deleted author null, the trashed-aware query finds it.
    expect($posts[1]->relationLoaded('author'))->toBeTrue()
        ->and($posts[1]->getRelation('author'))->toBeNull();

    $trashed = $field->resolve($posts[1]);
    $live = $field->resolve($posts[0]);

    expect($trashed)->toMatchArray(['id' => 2, 'title' => 'Bruno', 'trashed' => true])
        ->and($live['title'])->toBe('Ana')
        ->and($live)->not->toHaveKey('trashed');
});

it('resolves a soft-deleted target the same with and without the eager load', function () {
    $field = BelongsTo::make('author')->titleAttribute('name');

    BtlArchivableAuthor::query()->whereKey(2)->first()->delete();

    $lazy = $field->resolve(BtlArchivedPost::query()->orderBy('id')->get()[1]);
    $eager = $field->resolve(BtlArchivedPost::query()->with('author')->orderBy('id')->get()[1]);

    expect($eager)->toBe($lazy);
});

it('resolves a loaded relation that is null because the foreign key points nowhere to a null title', function () {
    $field = BelongsTo::make('author')->titleAttribute('name');
    $post = BtlPost::create(['title' => 'Orphan', 'author_id' => 999]);

    $post = BtlPost::query()->with('author')->find($post->id);

    expect($post->getRelation('author'))->toBeNull()
        ->and($field->resolve($post))->toBe(['id' => 999, 'title' => null]);
});

// ---------------------------------------------------------------------------
// 4. An explicit titleAttribute() wins, loaded or not
// ---------------------------------------------------------------------------

it('lets an explicit titleAttribute() win over the related resource title, with and without the eager load', function () {
    $field = BelongsTo::make('author')->relatedResource('btl-authors')->titleAttribute('nickname');

    $lazy = $field->resolve(BtlPost::query()->orderBy('id')->first());
    $eager = $field->resolve(BtlPost::query()->with('author')->orderBy('id')->first());

    expect($lazy['title'])->toBe('ana-nick')
        ->and($eager['title'])->toBe('ana-nick');
});
