<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Slug;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class SlugPostModel extends Model
{
    protected $table = 'slug_posts';

    protected $fillable = ['title', 'slug', 'published_at'];
}

class SlugPostResource extends Resource
{
    public static function model(): string
    {
        return SlugPostModel::class;
    }

    public static function titleAttribute(): string
    {
        return 'title';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            Slug::make('slug')
                ->from('title')
                ->reserved(['admin', 'api', 'login'])
                ->lockAfter(fn ($m) => $m->getAttribute('published_at') !== null),
        ];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('slug_posts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->string('slug')->unique();
        $table->timestamp('published_at')->nullable();
        $table->timestamps();
    });

    app(ResourceRegistry::class)->register(SlugPostResource::class);

    SlugPostModel::create(['title' => 'Existing Post', 'slug' => 'existing-post']);
});

afterEach(function () {
    Schema::dropIfExists('slug_posts');
    app(ResourceRegistry::class)->flush();
});

// ---------------------------------------------------------------------------
// Tests — happy path
// ---------------------------------------------------------------------------

it('returns available=true for a free slug', function () {
    $res = $this->getJson('/martis/api/resources/slug-post-models/slug-check/slug?value=brand-new-post');

    $res->assertOk();
    $res->assertJsonPath('data.available', true);
    $res->assertJsonPath('data.reserved', false);
    $res->assertJsonPath('data.suggestion', null);
});

it('returns available=false with a numeric suggestion for a taken slug', function () {
    $res = $this->getJson('/martis/api/resources/slug-post-models/slug-check/slug?value=existing-post');

    $res->assertOk();
    $res->assertJsonPath('data.available', false);
    $res->assertJsonPath('data.reserved', false);
    $res->assertJsonPath('data.suggestion', 'existing-post-2');
});

it('returns reserved=true + suggestion for a reserved value', function () {
    $res = $this->getJson('/martis/api/resources/slug-post-models/slug-check/slug?value=admin');

    $res->assertOk();
    $res->assertJsonPath('data.reserved', true);
    $res->assertJsonPath('data.available', false);
    // Suggestion is "admin-2" (free of collisions and not reserved itself).
    $res->assertJsonPath('data.suggestion', 'admin-2');
});

it('normalises the value before checking (São Paulo → sao-paulo)', function () {
    $res = $this->getJson('/martis/api/resources/slug-post-models/slug-check/slug?value=S%C3%A3o%20Paulo');

    $res->assertOk();
    $res->assertJsonPath('data.available', true);
});

it('treats an empty value as unavailable with no suggestion', function () {
    $res = $this->getJson('/martis/api/resources/slug-post-models/slug-check/slug?value=');

    $res->assertOk();
    $res->assertJsonPath('data.available', false);
    $res->assertJsonPath('data.suggestion', null);
});

it('excludes the record being edited from the uniqueness probe (?id=)', function () {
    $existing = SlugPostModel::first();

    $res = $this->getJson(
        '/martis/api/resources/slug-post-models/slug-check/slug'
        ."?value={$existing->slug}&id={$existing->getKey()}"
    );

    // The only row holding this slug IS the row we're editing — so it's
    // available as far as the editor is concerned.
    $res->assertOk();
    $res->assertJsonPath('data.available', true);
});

it('returns 404 when the slug field is not registered on the resource', function () {
    $res = $this->getJson('/martis/api/resources/slug-post-models/slug-check/nonexistent?value=foo');

    $res->assertStatus(404);
});

it('returns 404 when the resource does not exist', function () {
    $res = $this->getJson('/martis/api/resources/no-such-resource/slug-check/slug?value=foo');

    $res->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Tests — lockAfter behaviour across the update pipeline
// ---------------------------------------------------------------------------

it('update endpoint accepts closure-based validation rules (regression: Slug + common-password closures)', function () {
    /** @var SlugPostModel $post */
    $post = SlugPostModel::create(['title' => 'Update Me', 'slug' => 'update-me']);

    // Issue a PUT update — this hits ResourceController::validateRequest,
    // whose previous type-hint rejected Closure rules and produced a 500.
    $res = $this->putJson("/martis/api/resources/slug-post-models/{$post->id}", [
        'title' => 'Update Me',
        'slug' => 'update-me-renamed',
    ]);

    $res->assertSuccessful();
    expect($post->fresh()->slug)->toBe('update-me-renamed');
});

it('Slug::fill silently ignores writes once lockAfter applies', function () {
    /** @var SlugPostModel $post */
    $post = SlugPostModel::create([
        'title' => 'Published Post',
        'slug' => 'published-post',
        'published_at' => now(),
    ]);

    $field = Slug::make('slug')->lockAfter(fn ($m) => $m->getAttribute('published_at') !== null);
    $field->fill($post, 'hijacked-slug');
    $post->save();

    expect($post->fresh()->slug)->toBe('published-post');
});
