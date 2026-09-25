<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Enums\AggregateFunction;
use Martis\Fields\HasOne;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A HasOne / MorphOne card (the one-of-many variants included) shows the
 * related record as its detail view, as Nova does: Nova hides the panel when
 * the related `view` policy denies the record (nova-dusk-suite
 * HasOneAuthorizationTest). A record the user may not view reads as no
 * record: the card is empty and its Edit / Delete answer 404. The "1 of N"
 * count and the aggregate tile count what the related index lists, so a row
 * its indexQuery() or scopes() hide is not counted.
 */

class RCVParentModel extends Model
{
    protected $table = 'rcv_parents';

    protected $fillable = ['name'];

    public function profile(): EloquentHasOne
    {
        return $this->hasOne(RCVNoteModel::class, 'parent_id');
    }

    public function notes(): EloquentHasMany
    {
        return $this->hasMany(RCVNoteModel::class, 'parent_id');
    }

    public function comment(): EloquentMorphOne
    {
        return $this->morphOne(RCVCommentModel::class, 'commentable');
    }

    public function comments(): EloquentMorphMany
    {
        return $this->morphMany(RCVCommentModel::class, 'commentable');
    }
}

class RCVNoteModel extends Model
{
    protected $table = 'rcv_notes';

    protected $fillable = ['title', 'amount', 'written_at', 'parent_id'];
}

class RCVCommentModel extends Model
{
    protected $table = 'rcv_comments';

    protected $fillable = ['title', 'amount', 'written_at', 'commentable_type', 'commentable_id'];
}

/** Hides "By index" from the index; the "Private" record may not be viewed. */
abstract class RCVRelatedResource extends Resource
{
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('title'), '!=', 'By index');
    }

    public static function scopes(Request $request): array
    {
        return [fn (Builder $query) => $query->where($query->qualifyColumn('title'), '!=', 'By scope')];
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }

    public function authorizedToView(Request $request): bool
    {
        return $this->model?->getAttribute('title') !== 'Private';
    }
}

class RCVNoteResource extends RCVRelatedResource
{
    public static function model(): string
    {
        return RCVNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rcv-notes';
    }
}

class RCVCommentResource extends RCVRelatedResource
{
    public static function model(): string
    {
        return RCVCommentModel::class;
    }

    public static function uriKey(): string
    {
        return 'rcv-comments';
    }
}

class RCVParentResource extends Resource
{
    public static function model(): string
    {
        return RCVParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'rcv-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::make('Profile', 'profile')->relatedResource('rcv-notes'),
            MorphOne::make('Comment', 'comment')->relatedResource('rcv-comments'),
            HasOne::ofMany('Latest note', 'notes', RCVNoteResource::class)->latestByTimestamp('written_at')->aggregateVia(AggregateFunction::Sum, 'amount'),
            MorphOne::ofMany('Latest comment', 'comments', RCVCommentResource::class)->latestByTimestamp('written_at')->aggregateVia(AggregateFunction::Sum, 'amount'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['rcv_comments', 'rcv_notes', 'rcv_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rcv_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rcv_notes', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->string('title');
        $table->integer('amount')->default(0);
        $table->timestamp('written_at')->nullable();
        $table->timestamps();
    });
    Schema::create('rcv_comments', function ($table) {
        $table->id();
        $table->morphs('commentable');
        $table->string('title');
        $table->integer('amount')->default(0);
        $table->timestamp('written_at')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RCVNoteResource::class, RCVCommentResource::class, RCVParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->parent = RCVParentModel::create(['name' => 'Parent']);
});

afterEach(function () {
    foreach (['rcv_comments', 'rcv_notes', 'rcv_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

function rcvCard(string $path): string
{
    return '/martis/api/resources/rcv-parents/'.test()->parent->id.'/'.$path;
}

/** [card path, the relation that holds its records] */
dataset('rcv cards', [
    'has-one' => ['has-one/profile', 'notes'],
    'morph-one' => ['morph-one/comment', 'comments'],
    'has-one of many' => ['has-one/notes', 'notes'],
    'morph-one of many' => ['morph-one/comments', 'comments'],
]);

it('shows an empty card for a record the user may not view, and refuses to write it', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Private', 'written_at' => now()]);

    $this->getJson(rcvCard($path))->assertOk()->assertJsonPath('data', null);
    $this->putJson(rcvCard($path), ['title' => 'Renamed'])->assertStatus(404);
    $this->deleteJson(rcvCard($path))->assertStatus(404);

    expect($record->fresh()?->title)->toBe('Private');
})->with('rcv cards');

it('shows and writes a record the user may view (control)', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Visible', 'written_at' => now()]);

    $this->getJson(rcvCard($path))->assertOk()->assertJsonPath('data.id', $record->id);
    $this->putJson(rcvCard($path), ['title' => 'Renamed'])->assertOk();

    expect($record->fresh()->title)->toBe('Renamed');
})->with('rcv cards');

it('counts in "1 of N" and the aggregate only the records the related index lists', function (string $path, string $relation) {
    foreach (['Visible' => 1, 'By index' => 10, 'By scope' => 100] as $title => $amount) {
        $this->parent->{$relation}()->create(['title' => $title, 'amount' => $amount, 'written_at' => now()->subMinutes($amount)]);
    }

    $meta = $this->getJson(rcvCard($path))->assertOk()->json('meta.ofMany');

    expect($meta['totalCount'])->toBe(1)
        ->and((int) $meta['aggregate']['value'])->toBe(1);
})->with([
    'has-one of many' => ['has-one/notes', 'notes'],
    'morph-one of many' => ['morph-one/comments', 'comments'],
]);
