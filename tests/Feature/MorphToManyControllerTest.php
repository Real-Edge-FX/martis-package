<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\MorphToMany;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — Posts and Videos can both be tagged via `taggables` pivot
// table (`tag_id`, `taggable_type`, `taggable_id`, plus an extra `weight`
// pivot field to exercise pivot-data round-tripping). The same TagResource
// is reused for both parents — the controller must scope by morph type.
// ---------------------------------------------------------------------------

class MTMPostModel extends Model
{
    protected $table = 'mtm_test_posts';

    protected $fillable = ['title'];

    public function tags(): EloquentMorphToMany
    {
        return $this->morphToMany(MTMTagModel::class, 'taggable', 'mtm_test_taggables', null, 'tag_id')
            ->withPivot('weight');
    }
}

class MTMVideoModel extends Model
{
    protected $table = 'mtm_test_videos';

    protected $fillable = ['title'];

    public function tags(): EloquentMorphToMany
    {
        return $this->morphToMany(MTMTagModel::class, 'taggable', 'mtm_test_taggables', null, 'tag_id')
            ->withPivot('weight');
    }
}

class MTMTagModel extends Model
{
    protected $table = 'mtm_test_tags';

    protected $fillable = ['name'];
}

class MTMPostResource extends Resource
{
    public static function model(): string
    {
        return MTMPostModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            MorphToMany::make('Tags', 'tags')
                ->relatedResource('m-t-m-tag-models')
                ->fields(fn () => [Number::make('weight')->nullable()]),
        ];
    }
}

class MTMVideoResource extends Resource
{
    public static function model(): string
    {
        return MTMVideoModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            MorphToMany::make('Tags', 'tags')
                ->relatedResource('m-t-m-tag-models')
                ->fields(fn () => [Number::make('weight')->nullable()]),
        ];
    }
}

class MTMTagResource extends Resource
{
    public static function model(): string
    {
        return MTMTagModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required()->searchable()->sortable(),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('mtm_test_taggables');
    Schema::dropIfExists('mtm_test_tags');
    Schema::dropIfExists('mtm_test_posts');
    Schema::dropIfExists('mtm_test_videos');

    Schema::create('mtm_test_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('mtm_test_videos', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('mtm_test_tags', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('mtm_test_taggables', function ($table) {
        $table->id();
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $table->integer('weight')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MTMPostResource::class);
    $registry->register(MTMVideoResource::class);
    $registry->register(MTMTagResource::class);
});

afterEach(function () {
    Schema::dropIfExists('mtm_test_taggables');
    Schema::dropIfExists('mtm_test_tags');
    Schema::dropIfExists('mtm_test_posts');
    Schema::dropIfExists('mtm_test_videos');
});

// ---------------------------------------------------------------------------
// Index — listing attached records scoped by morph type
// ---------------------------------------------------------------------------

it('lists tags attached to a polymorphic parent', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $tag1 = MTMTagModel::create(['name' => 'php']);
    $tag2 = MTMTagModel::create(['name' => 'laravel']);
    $post->tags()->attach([$tag1->id, $tag2->id]);

    $response = $this->getJson("/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags");

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('does not leak tags attached to another morph type with the same ID', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $video = MTMVideoModel::create(['title' => 'Video']);
    $tag = MTMTagModel::create(['name' => 'shared']);

    // Attach `shared` only to the Video.
    $video->tags()->attach($tag->id);

    $postResp = $this->getJson("/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags");
    expect($postResp->json('meta.total'))->toBe(0);

    $videoResp = $this->getJson("/martis/api/resources/m-t-m-video-models/{$video->id}/morph-to-many/tags");
    expect($videoResp->json('meta.total'))->toBe(1);
});

it('exposes pivot data in the attached-tags index', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $tag = MTMTagModel::create(['name' => 'featured']);
    $post->tags()->attach($tag->id, ['weight' => 7]);

    $response = $this->getJson("/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags");

    $response->assertOk();
    $row = $response->json('data.0');
    expect($row)->not->toBeNull();
    expect($row)->toHaveKey('_pivot');
    expect((int) $row['_pivot']['weight'])->toBe(7);
});

// ---------------------------------------------------------------------------
// Attachable — listing candidate tags
// ---------------------------------------------------------------------------

it('lists attachable (not-yet-attached) tags', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $attached = MTMTagModel::create(['name' => 'attached']);
    $available = MTMTagModel::create(['name' => 'available']);
    $post->tags()->attach($attached->id);

    $response = $this->getJson("/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/attachable");

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('available');
    expect($names)->not->toContain('attached');
});

// ---------------------------------------------------------------------------
// Attach / detach
// ---------------------------------------------------------------------------

it('attaches a tag to a polymorphic parent', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $tag = MTMTagModel::create(['name' => 'fresh']);

    $response = $this->postJson(
        "/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/attach",
        ['related_id' => $tag->id],
    );

    expect($response->status())->toBeIn([200, 201]);
    expect($post->tags()->count())->toBe(1);
});

it('attaches a tag with pivot data', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $tag = MTMTagModel::create(['name' => 'rich']);

    $response = $this->postJson(
        "/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/attach",
        ['related_id' => $tag->id, 'weight' => 12],
    );

    expect($response->status())->toBeIn([200, 201]);
    $pivotRow = \DB::table('mtm_test_taggables')
        ->where('taggable_type', MTMPostModel::class)
        ->where('taggable_id', $post->id)
        ->where('tag_id', $tag->id)
        ->first();
    expect($pivotRow)->not->toBeNull();
    expect((int) $pivotRow->weight)->toBe(12);
});

it('detaches a tag from a polymorphic parent', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $tag = MTMTagModel::create(['name' => 'doomed']);
    $post->tags()->attach($tag->id);

    $response = $this->deleteJson(
        "/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/{$tag->id}/detach",
    );

    $response->assertOk();
    expect($post->tags()->count())->toBe(0);
});

it('detach is scoped — does not detach from another morph type', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $video = MTMVideoModel::create(['title' => 'Video']);
    $tag = MTMTagModel::create(['name' => 'shared']);

    $video->tags()->attach($tag->id);

    $response = $this->deleteJson(
        "/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/{$tag->id}/detach",
    );

    expect($response->status())->toBeIn([200, 404]);
    // The Video's attachment must still exist regardless of the response code.
    expect($video->tags()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Pivot update
// ---------------------------------------------------------------------------

it('updates pivot data for a polymorphic attachment', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $tag = MTMTagModel::create(['name' => 'tagged']);
    $post->tags()->attach($tag->id, ['weight' => 1]);

    $response = $this->putJson(
        "/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/{$tag->id}/pivot",
        ['weight' => 99],
    );

    expect($response->status())->toBeIn([200, 204]);

    $pivotRow = \DB::table('mtm_test_taggables')
        ->where('taggable_type', MTMPostModel::class)
        ->where('taggable_id', $post->id)
        ->where('tag_id', $tag->id)
        ->first();
    expect((int) $pivotRow->weight)->toBe(99);
});

// ---------------------------------------------------------------------------
// Validation + error handling
// ---------------------------------------------------------------------------

it('rejects attach without related_id', function () {
    $post = MTMPostModel::create(['title' => 'Post']);

    $response = $this->postJson(
        "/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/tags/attach",
        [],
    );

    $response->assertStatus(422);
});

it('returns 404 for unknown parent resource on morph-to-many', function () {
    $response = $this->getJson('/martis/api/resources/nonexistent/1/morph-to-many/tags');
    $response->assertStatus(404);
});

it('returns 404 for unknown parent record on morph-to-many', function () {
    $response = $this->getJson('/martis/api/resources/m-t-m-post-models/99999/morph-to-many/tags');
    $response->assertStatus(404);
});

it('returns 404 for unknown morph-to-many relationship name', function () {
    $post = MTMPostModel::create(['title' => 'Post']);
    $response = $this->getJson("/martis/api/resources/m-t-m-post-models/{$post->id}/morph-to-many/nonexistent");
    $response->assertStatus(404);
});
