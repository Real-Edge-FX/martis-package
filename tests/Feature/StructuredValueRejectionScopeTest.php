<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\BooleanGroup;
use Martis\Fields\Image;
use Martis\Fields\KeyValue;
use Martis\Fields\MorphTo;
use Martis\Fields\MultiSelect;
use Martis\Fields\Repeater;
use Martis\Fields\Sparkline;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Which structured values the controllers reject (v1.38.0).
//
// A structured field whose value is a string that is not JSON for a list or
// map fails validation, so the package's own fill() never empties or
// overwrites what is stored. The rejection must not reach values that fill()
// ignores anyway: a MorphTo only reads a target map (the update forms up to
// v1.37.3 send a bare id for an untouched target, and a morph key can be a
// string), a readonly or computed field writes nothing, and a fillUsing()
// callback decides for itself which shapes it accepts.
// ===========================================================================

class SVRPostModel extends Model
{
    protected $table = 'svr_posts';

    protected $guarded = [];

    public $timestamps = false;
}

class SVRCommentModel extends Model
{
    protected $table = 'svr_comments';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['states' => 'array'];

    public function commentable(): EloquentMorphTo
    {
        return $this->morphTo();
    }
}

class SVRPostResource extends Resource
{
    public static function model(): string
    {
        return SVRPostModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class SVRCommentResource extends Resource
{
    public static function model(): string
    {
        return SVRCommentModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('body'),
            Image::make('logo')->disk('svr_disk')->storagePath('logos')->nullable(),
            MorphTo::make('commentable', 'Commentable')->types([SVRPostResource::class])->nullable(),
            MultiSelect::make('states')->options(['a' => 'A', 'b' => 'B'])->readonly(),
            MultiSelect::make('labels')->options(['x' => 'X', 'y' => 'Y'])
                ->fillUsing(function (Model $model, mixed $value): void {
                    $model->labels = is_array($value) ? implode(',', $value) : (string) $value;
                }),
        ];
    }
}

function svrMultipartUpdate($test, int $id, array $parameters)
{
    return $test->call(
        'POST',
        "/martis/api/resources/s-v-r-comment-models/{$id}",
        ['_method' => 'PUT'] + $parameters,
        [],
        ['logo' => UploadedFile::fake()->image('logo.png', 16, 16)],
        ['HTTP_ACCEPT' => 'application/json'],
    );
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Storage::fake('svr_disk');

    Schema::dropIfExists('svr_comments');
    Schema::dropIfExists('svr_posts');
    Schema::create('svr_posts', function ($table) {
        $table->id();
        $table->string('title')->nullable();
    });
    Schema::create('svr_comments', function ($table) {
        $table->id();
        $table->string('body')->nullable();
        $table->string('logo')->nullable();
        $table->string('commentable_type')->nullable();
        $table->string('commentable_id')->nullable();
        $table->json('states')->nullable();
        $table->string('labels')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SVRPostResource::class);
    $registry->register(SVRCommentResource::class);

    $this->post = SVRPostModel::create(['title' => 'First']);
    $this->other = SVRPostModel::create(['title' => 'Second']);
    $this->comment = SVRCommentModel::create([
        'body' => 'old',
        'commentable_type' => SVRPostModel::class,
        'commentable_id' => (string) $this->post->id,
        'states' => ['a'],
        'labels' => 'x',
    ]);
});

afterEach(function () {
    Schema::dropIfExists('svr_comments');
    Schema::dropIfExists('svr_posts');
});

it('saves a record whose MorphTo arrives as the bare id older update forms send', function () {
    svrMultipartUpdate($this, $this->comment->id, [
        'body' => 'new',
        'commentable' => (string) $this->post->id,
    ])->assertStatus(200);

    $this->putJson("/martis/api/resources/s-v-r-comment-models/{$this->comment->id}", [
        'body' => 'newer',
        'commentable' => '01HZX3ULIDLIKEKEY',
    ])->assertStatus(200);

    $fresh = $this->comment->fresh();
    expect($fresh->body)->toBe('newer')
        ->and($fresh->commentable_type)->toBe(SVRPostModel::class)
        ->and($fresh->commentable_id)->toBe((string) $this->post->id);
});

it('moves a MorphTo to the target map an update form sends, on both request paths', function () {
    $this->putJson("/martis/api/resources/s-v-r-comment-models/{$this->comment->id}", [
        'commentable' => ['resourceType' => 's-v-r-post-models', 'id' => $this->other->id, 'title' => 'Second'],
    ])->assertStatus(200);

    expect($this->comment->fresh()->commentable_id)->toBe((string) $this->other->id);

    svrMultipartUpdate($this, $this->comment->id, [
        'commentable' => json_encode(['type' => SVRPostModel::class, 'id' => $this->post->id, 'title' => 'First', 'resourceType' => 's-v-r-post-models']),
    ])->assertStatus(200);

    expect($this->comment->fresh()->commentable_id)->toBe((string) $this->post->id);
});

it('ignores a MorphTo type that is not one of the field\'s types', function () {
    $this->putJson("/martis/api/resources/s-v-r-comment-models/{$this->comment->id}", [
        'commentable' => ['type' => SVRCommentModel::class, 'id' => $this->comment->id],
    ])->assertStatus(200);

    $fresh = $this->comment->fresh();
    expect($fresh->commentable_type)->toBe(SVRPostModel::class)
        ->and($fresh->commentable_id)->toBe((string) $this->post->id);

    // An allowed type is still honoured by class name.
    $this->putJson("/martis/api/resources/s-v-r-comment-models/{$this->comment->id}", [
        'commentable' => ['type' => SVRPostModel::class, 'id' => $this->other->id],
    ])->assertStatus(200);

    expect($this->comment->fresh()->commentable_id)->toBe((string) $this->other->id);
});

it('does not reject an unstructured value for a readonly field', function () {
    svrMultipartUpdate($this, $this->comment->id, [
        'states' => '[object Object]',
    ])->assertStatus(200);

    expect($this->comment->fresh()->states)->toBe(['a']);
});

it('leaves the shape of the value to a fillUsing() callback', function () {
    svrMultipartUpdate($this, $this->comment->id, [
        'labels' => 'x,y',
    ])->assertStatus(200);

    expect($this->comment->fresh()->labels)->toBe('x,y');
});

it('reports rejectsUnstructuredValue() only for structured fields the package fills itself', function () {
    expect(Repeater::make('r')->rejectsUnstructuredValue())->toBeTrue()
        ->and(MultiSelect::make('m')->rejectsUnstructuredValue())->toBeTrue()
        ->and(BooleanGroup::make('b')->rejectsUnstructuredValue())->toBeTrue()
        ->and(KeyValue::make('k')->rejectsUnstructuredValue())->toBeTrue()
        ->and(Tag::make('tags', 'tags')->rejectsUnstructuredValue())->toBeTrue()
        ->and(Sparkline::make('s')->rejectsUnstructuredValue())->toBeTrue()
        ->and(MorphTo::make('owner', 'owner')->rejectsUnstructuredValue())->toBeFalse()
        ->and(MultiSelect::make('m')->readonly()->rejectsUnstructuredValue())->toBeFalse()
        ->and(MultiSelect::make('m')->computed()->rejectsUnstructuredValue())->toBeFalse()
        ->and(MultiSelect::make('m')->fillUsing(fn () => null)->rejectsUnstructuredValue())->toBeFalse()
        ->and(Text::make('t')->rejectsUnstructuredValue())->toBeFalse();
});
