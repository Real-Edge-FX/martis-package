<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Cache\MartisCache;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A relationship field follows its related resource's viewAny, as in Nova
// (v2.0.1). Nova's HasMany / HasOne / BelongsToMany (and the fields built on
// them) authorize with `$resourceClass::authorizedToViewAny($request) &&
// parent::authorize($request)`, and its relationship index (the related
// resource's index) aborts with 403 without viewAny. Before, Martis kept the
// panel on the detail page and listed the related records to any user who
// could view the parent.
// ===========================================================================

/** Which related resources the current user may list, per test. */
class RRVState
{
    /** @var array<string, bool> */
    public static array $open = [];
}

class RRVPost extends Model
{
    protected $table = 'rrv_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function comments(): EloquentHasMany
    {
        return $this->hasMany(RRVComment::class, 'post_id');
    }

    public function secretComments(): EloquentHasMany
    {
        return $this->hasMany(RRVComment::class, 'post_id');
    }

    public function pinnedComment(): EloquentHasOne
    {
        return $this->hasOne(RRVComment::class, 'post_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RRVNote::class, 'notable');
    }

    public function featuredNote(): EloquentMorphOne
    {
        return $this->morphOne(RRVNote::class, 'notable');
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RRVTag::class, 'rrv_post_tag', 'post_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(RRVTag::class, 'taggable', 'rrv_taggables', null, 'tag_id');
    }
}

class RRVComment extends Model
{
    protected $table = 'rrv_comments';

    protected $guarded = [];

    public $timestamps = false;
}

class RRVNote extends Model
{
    protected $table = 'rrv_notes';

    protected $guarded = [];

    public $timestamps = false;
}

class RRVTag extends Model
{
    protected $table = 'rrv_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class RRVTouchPivot extends Action
{
    public ?string $name = 'Touch Pivot';

    /** @param Collection<int, Model> $models */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        return ActionResponse::message('Touched.');
    }
}

abstract class RRVRelatedResource extends Resource
{
    public function authorizedToViewAny(Request $request): bool
    {
        return RRVState::$open[static::uriKey()] ?? true;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class RRVCommentResource extends RRVRelatedResource
{
    public static function model(): string
    {
        return RRVComment::class;
    }

    public static function uriKey(): string
    {
        return 'rrv-comments';
    }
}

class RRVNoteResource extends RRVRelatedResource
{
    public static function model(): string
    {
        return RRVNote::class;
    }

    public static function uriKey(): string
    {
        return 'rrv-notes';
    }
}

class RRVTagResource extends RRVRelatedResource
{
    public static function model(): string
    {
        return RRVTag::class;
    }

    public static function uriKey(): string
    {
        return 'rrv-tags';
    }
}

class RRVPostResource extends Resource
{
    public static function model(): string
    {
        return RRVPost::class;
    }

    public static function uriKey(): string
    {
        return 'rrv-posts';
    }

    public function fields(Request $request): array
    {
        $actions = fn () => [RRVTouchPivot::make()];

        return [
            Text::make('title'),
            HasMany::make('Comments', 'comments', RRVCommentResource::class),
            // Hidden by its own canSee(): a 404, whatever the related viewAny.
            HasMany::make('Secret Comments', 'secretComments', RRVCommentResource::class)->canSee(fn (): bool => false),
            HasOne::make('Pinned Comment', 'pinnedComment', RRVCommentResource::class),
            MorphMany::make('Notes', 'notes', RRVNoteResource::class),
            MorphOne::make('Featured Note', 'featuredNote', RRVNoteResource::class),
            BelongsToMany::make('Tags', 'tags', RRVTagResource::class)->actions($actions),
            MorphToMany::make('Labels', 'labels', RRVTagResource::class)->actions($actions),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    RRVState::$open = [];

    foreach (['rrv_posts', 'rrv_comments', 'rrv_notes', 'rrv_tags', 'rrv_post_tag', 'rrv_taggables'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('rrv_posts', function ($t) {
        $t->id();
        $t->string('title');
    });
    Schema::create('rrv_comments', function ($t) {
        $t->id();
        $t->unsignedBigInteger('post_id');
        $t->string('name');
    });
    Schema::create('rrv_notes', function ($t) {
        $t->id();
        $t->morphs('notable');
        $t->string('name');
    });
    Schema::create('rrv_tags', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('rrv_post_tag', function ($t) {
        $t->unsignedBigInteger('post_id');
        $t->unsignedBigInteger('tag_id');
    });
    Schema::create('rrv_taggables', function ($t) {
        $t->unsignedBigInteger('tag_id');
        $t->morphs('taggable');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RRVPostResource::class, RRVCommentResource::class, RRVNoteResource::class, RRVTagResource::class] as $class) {
        $registry->register($class);
    }

    $this->post = RRVPost::query()->create(['title' => 'Post']);
    RRVComment::query()->create(['post_id' => $this->post->id, 'name' => 'Comment']);
    $this->post->notes()->create(['name' => 'Note']);
    $tag = RRVTag::query()->create(['name' => 'Tag']);
    $this->post->tags()->attach($tag->id);
    $this->post->labels()->attach($tag->id);
});

afterEach(function () {
    RRVState::$open = [];
    app(ResourceRegistry::class)->flush();

    foreach (['rrv_posts', 'rrv_comments', 'rrv_notes', 'rrv_tags', 'rrv_post_tag', 'rrv_taggables'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** @return list<string> */
function rrvDetailRelationships(): array
{
    // The schema is cached per user; each read here follows a state change.
    app(MartisCache::class)->clear('schema');

    return collect(test()->getJson('/martis/api/resources/rrv-posts/schema')->assertOk()->json('data.fieldsForDetail'))
        ->pluck('relationship')
        ->filter()
        ->values()
        ->all();
}

dataset('rrv panels', [
    'has-many' => ['rrv-comments', 'comments', '/has-many/comments'],
    'has-one' => ['rrv-comments', 'pinnedComment', '/has-one/pinnedComment'],
    'morph-many' => ['rrv-notes', 'notes', '/morph-many/notes'],
    'morph-one' => ['rrv-notes', 'featuredNote', '/morph-one/featuredNote'],
    'belongs-to-many' => ['rrv-tags', 'tags', '/belongs-to-many/tags'],
    'morph-to-many' => ['rrv-tags', 'labels', '/morph-to-many/labels'],
    'belongs-to-many attachable' => ['rrv-tags', 'tags', '/belongs-to-many/tags/attachable'],
    'belongs-to-many pivot actions' => ['rrv-tags', 'tags', '/belongs-to-many/tags/actions'],
    'morph-to-many pivot actions' => ['rrv-tags', 'labels', '/morph-to-many/labels/actions'],
]);

it('leaves a relationship panel off the detail page when its related resource denies viewAny', function (string $related, string $relationship) {
    expect(rrvDetailRelationships())->toContain($relationship);

    RRVState::$open[$related] = false;

    expect(rrvDetailRelationships())->not->toContain($relationship);
})->with('rrv panels');

it('answers 403 on a relationship route when its related resource denies viewAny', function (string $related, string $relationship, string $path) {
    $url = '/martis/api/resources/rrv-posts/'.$this->post->id.$path;

    $this->getJson($url)->assertOk();

    RRVState::$open[$related] = false;

    $this->getJson($url)->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
})->with('rrv panels');

it('keeps the 404 of a relationship field its own canSee hides, whatever the related viewAny', function (bool $open) {
    RRVState::$open['rrv-comments'] = $open;

    $this->getJson('/martis/api/resources/rrv-posts/'.$this->post->id.'/has-many/secretComments')->assertNotFound();
})->with([true, false]);

it('keeps the other panels when one related resource denies viewAny', function () {
    RRVState::$open['rrv-comments'] = false;

    expect(rrvDetailRelationships())->toBe(['notes', 'featuredNote', 'tags', 'labels']);
    $this->getJson('/martis/api/resources/rrv-posts/'.$this->post->id.'/morph-many/notes')->assertOk()->assertJsonCount(1, 'data');
});
