<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\MorphMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — two unrelated parents (Post, Video) sharing a polymorphic
// child (Comment) via `commentable_type` + `commentable_id`. The same
// CommentResource is reused for both parents — the controller must scope
// by morph type so a comment on Post #1 never leaks into Video #1's list.
// ---------------------------------------------------------------------------

class MMPostModel extends Model
{
    protected $table = 'mm_test_posts';

    protected $fillable = ['title'];

    public function comments(): EloquentMorphMany
    {
        return $this->morphMany(MMCommentModel::class, 'commentable');
    }
}

class MMVideoModel extends Model
{
    protected $table = 'mm_test_videos';

    protected $fillable = ['title'];

    public function comments(): EloquentMorphMany
    {
        return $this->morphMany(MMCommentModel::class, 'commentable');
    }
}

class MMCommentModel extends Model
{
    protected $table = 'mm_test_comments';

    protected $fillable = ['body', 'commentable_type', 'commentable_id'];
}

class MMPostResource extends Resource
{
    public static function model(): string
    {
        return MMPostModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            MorphMany::make('Comments', 'comments')->relatedResource('m-m-comment-models'),
        ];
    }
}

class MMVideoResource extends Resource
{
    public static function model(): string
    {
        return MMVideoModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            MorphMany::make('Comments', 'comments')->relatedResource('m-m-comment-models'),
        ];
    }
}

class MMCommentResource extends Resource
{
    public static function model(): string
    {
        return MMCommentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('body')->required()->searchable()->sortable(),
        ];
    }
}

// No-create variant for authorization tests.
class MMNoCreateCommentResource extends MMCommentResource
{
    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('mm_test_comments');
    Schema::dropIfExists('mm_test_posts');
    Schema::dropIfExists('mm_test_videos');

    Schema::create('mm_test_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('mm_test_videos', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('mm_test_comments', function ($table) {
        $table->id();
        $table->text('body');
        $table->morphs('commentable');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MMPostResource::class);
    $registry->register(MMVideoResource::class);
    $registry->register(MMCommentResource::class);
    $registry->register(MMSectionPostResource::class);
});

afterEach(function () {
    Schema::dropIfExists('mm_test_comments');
    Schema::dropIfExists('mm_test_posts');
    Schema::dropIfExists('mm_test_videos');
});

// ---------------------------------------------------------------------------
// Index — listing children scoped by morph type
// ---------------------------------------------------------------------------

it('lists comments scoped to the morph parent', function () {
    $post = MMPostModel::create(['title' => 'Post A']);
    MMCommentModel::create(['body' => 'Hello', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);
    MMCommentModel::create(['body' => 'World', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);

    $response = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments");

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('does not leak comments across morph types with the same ID', function () {
    $post = MMPostModel::create(['title' => 'Post A']);
    $video = MMVideoModel::create(['title' => 'Video A']);

    MMCommentModel::create(['body' => 'Post comment', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);
    MMCommentModel::create(['body' => 'Video comment', 'commentable_type' => MMVideoModel::class, 'commentable_id' => $video->id]);

    $postResponse = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments");
    expect($postResponse->json('meta.total'))->toBe(1);
    expect($postResponse->json('data.0.body'))->toBe('Post comment');

    $videoResponse = $this->getJson("/martis/api/resources/m-m-video-models/{$video->id}/morph-many/comments");
    expect($videoResponse->json('meta.total'))->toBe(1);
    expect($videoResponse->json('data.0.body'))->toBe('Video comment');
});

it('returns empty list when parent has no comments', function () {
    $post = MMPostModel::create(['title' => 'Lonely']);

    $response = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments");

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(0);
});

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------

it('paginates the morph-many index', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    for ($i = 1; $i <= 12; $i++) {
        MMCommentModel::create([
            'body' => "Comment {$i}",
            'commentable_type' => MMPostModel::class,
            'commentable_id' => $post->id,
        ]);
    }

    $response = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments?per_page=5");

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(12);
    expect($response->json('meta.per_page'))->toBe(5);
    expect($response->json('meta.last_page'))->toBe(3);
    expect(count($response->json('data')))->toBe(5);
});

// ---------------------------------------------------------------------------
// Search
// ---------------------------------------------------------------------------

it('searches the morph-many index by searchable field', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    MMCommentModel::create(['body' => 'apple pie', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);
    MMCommentModel::create(['body' => 'banana split', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);

    $response = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments?search=apple");

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.body'))->toBe('apple pie');
});

// ---------------------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------------------

it('sorts the morph-many index ascending and descending', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    MMCommentModel::create(['body' => 'zebra', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);
    MMCommentModel::create(['body' => 'alpha', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);

    $asc = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments?sort=body&direction=asc");
    expect($asc->json('data.0.body'))->toBe('alpha');

    $desc = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments?sort=body&direction=desc");
    expect($desc->json('data.0.body'))->toBe('zebra');
});

// ---------------------------------------------------------------------------
// Store — creating with both morph keys
// ---------------------------------------------------------------------------

it('stores a comment with both morph keys filled in', function () {
    $post = MMPostModel::create(['title' => 'Post A']);

    $response = $this->postJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments",
        ['body' => 'Fresh comment'],
    );

    $response->assertStatus(201);

    $comment = MMCommentModel::where('body', 'Fresh comment')->first();
    expect($comment)->not->toBeNull();
    expect($comment->commentable_type)->toBe(MMPostModel::class);
    expect((int) $comment->commentable_id)->toBe($post->id);
});

it('store validates required fields', function () {
    $post = MMPostModel::create(['title' => 'Post A']);

    $response = $this->postJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments",
        [],
    );

    $response->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Update — must preserve morph keys
// ---------------------------------------------------------------------------

it('updates a morph child without losing its morph keys', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    $comment = MMCommentModel::create([
        'body' => 'Original',
        'commentable_type' => MMPostModel::class,
        'commentable_id' => $post->id,
    ]);

    $response = $this->putJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments/{$comment->id}",
        ['body' => 'Updated'],
    );

    $response->assertOk();

    $comment->refresh();
    expect($comment->body)->toBe('Updated');
    expect($comment->commentable_type)->toBe(MMPostModel::class);
    expect((int) $comment->commentable_id)->toBe($post->id);
});

it('returns 404 when updating a comment that does not belong to this morph parent', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    $video = MMVideoModel::create(['title' => 'Video']);
    $videoComment = MMCommentModel::create([
        'body' => 'On video',
        'commentable_type' => MMVideoModel::class,
        'commentable_id' => $video->id,
    ]);

    $response = $this->putJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments/{$videoComment->id}",
        ['body' => 'Sneaky update'],
    );

    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Destroy — must scope to this morph parent
// ---------------------------------------------------------------------------

it('deletes a morph child', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    $comment = MMCommentModel::create([
        'body' => 'Doomed',
        'commentable_type' => MMPostModel::class,
        'commentable_id' => $post->id,
    ]);

    $response = $this->deleteJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments/{$comment->id}",
    );

    $response->assertOk();
    expect(MMCommentModel::find($comment->id))->toBeNull();
});

it('returns 404 when deleting a comment that does not belong to this morph parent', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    $video = MMVideoModel::create(['title' => 'Video']);
    $videoComment = MMCommentModel::create([
        'body' => 'On video',
        'commentable_type' => MMVideoModel::class,
        'commentable_id' => $video->id,
    ]);

    $response = $this->deleteJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments/{$videoComment->id}",
    );

    $response->assertStatus(404);
    expect(MMCommentModel::find($videoComment->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Error handling
// ---------------------------------------------------------------------------

it('returns 404 for unknown parent resource on morph-many', function () {
    $response = $this->getJson('/martis/api/resources/nonexistent/1/morph-many/comments');
    $response->assertStatus(404);
});

it('returns 404 for unknown parent record on morph-many', function () {
    $response = $this->getJson('/martis/api/resources/m-m-post-models/99999/morph-many/comments');
    $response->assertStatus(404);
});

it('returns 404 for unknown morph relationship name', function () {
    $post = MMPostModel::create(['title' => 'Post']);
    $response = $this->getJson("/martis/api/resources/m-m-post-models/{$post->id}/morph-many/nonexistent");
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('blocks store when the related resource forbids creation', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MMPostResource::class);
    $registry->register(MMVideoResource::class);
    $registry->register(MMNoCreateCommentResource::class);

    $post = MMPostModel::create(['title' => 'Post']);

    $response = $this->postJson(
        "/martis/api/resources/m-m-post-models/{$post->id}/morph-many/comments",
        ['body' => 'Forbidden'],
    );

    expect($response->status())->toBeIn([201, 403]);
});

/**
 * MorphMany field nested inside a layout Section — regression guard for the
 * unflattened resolveContext() that used to 404 the relation endpoints.
 */
class MMSectionPostResource extends Resource
{
    public static function model(): string
    {
        return MMPostModel::class;
    }

    public static function uriKey(): string
    {
        return 'mm-section-posts';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title')->required(),
            Section::make(null, [
                MorphMany::make('Comments', 'comments')->relatedResource('m-m-comment-models'),
            ]),
        ];
    }
}

it('resolves the morph-many index endpoint when the field sits inside a Section', function () {
    $post = MMPostModel::create(['title' => 'Post A']);
    MMCommentModel::create(['body' => 'c1', 'commentable_type' => MMPostModel::class, 'commentable_id' => $post->id]);

    $response = $this->getJson("/martis/api/resources/mm-section-posts/{$post->id}/morph-many/comments");

    $response->assertStatus(200);
    $response->assertJsonPath('meta.total', 1);
});
