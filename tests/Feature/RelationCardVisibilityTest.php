<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Enums\AggregateFunction;
use Martis\Fields\HasOne;
use Martis\Fields\HasOneThrough;
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

    public function newestNote(): EloquentHasOne
    {
        return $this->hasOne(RCVNoteModel::class, 'parent_id')->latestOfMany('written_at');
    }

    public function project(): EloquentHasOneThrough
    {
        return $this->hasOneThrough(RCVProjectModel::class, RCVTeamModel::class, 'parent_id', 'team_id');
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

class RCVTeamModel extends Model
{
    protected $table = 'rcv_teams';

    protected $fillable = ['parent_id', 'title'];
}

class RCVProjectModel extends Model
{
    protected $table = 'rcv_projects';

    protected $fillable = ['title', 'team_id'];
}

/** Hides "By index" from the index; the "Private" record may not be viewed. */
abstract class RCVRelatedResource extends Resource
{
    public static bool $groupById = false;

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        $query->where($query->qualifyColumn('title'), '!=', 'By index');

        return static::$groupById ? $query->groupBy($query->qualifyColumn('id')) : $query;
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

class RCVProjectResource extends RCVRelatedResource
{
    public static function model(): string
    {
        return RCVProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'rcv-projects';
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
            HasOne::ofMany('Newest note', 'newestNote', RCVNoteResource::class)->aggregateVia(AggregateFunction::Sum, 'amount'),
            HasOneThrough::make('Project', 'project')->relatedResource('rcv-projects'),
        ];
    }
}

const RCV_TABLES = ['rcv_projects', 'rcv_teams', 'rcv_comments', 'rcv_notes', 'rcv_parents'];

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (RCV_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rcv_teams', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->string('title')->default('Team');
        $table->timestamps();
    });
    Schema::create('rcv_projects', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('title');
        $table->timestamps();
    });
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
    foreach ([RCVNoteResource::class, RCVCommentResource::class, RCVProjectResource::class, RCVParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->parent = RCVParentModel::create(['name' => 'Parent']);
});

afterEach(function () {
    foreach (RCV_TABLES as $table) {
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
    'has-one of many, Eloquent latestOfMany()' => ['has-one/newestNote', 'notes'],
]);

it('hides the card of a record the user may not view, and refuses to write it', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Private', 'written_at' => now()]);

    $this->getJson(rcvCard($path))->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('meta.hidden', true)
        ->assertJsonMissingPath('meta.ofMany');
    $this->putJson(rcvCard($path), ['title' => 'Renamed'])->assertStatus(404);
    $this->deleteJson(rcvCard($path))->assertStatus(404);

    expect($record->fresh()?->title)->toBe('Private');
})->with('rcv cards');

it('shows and writes a record the user may view (control)', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Visible', 'written_at' => now()]);

    $this->getJson(rcvCard($path))->assertOk()
        ->assertJsonPath('data.id', $record->id)
        ->assertJsonMissingPath('meta.hidden');
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
    'has-one of many, Eloquent latestOfMany()' => ['has-one/newestNote', 'notes'],
]);

it('reports no hidden record on a card that has none', function (string $path) {
    $this->getJson(rcvCard($path))->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonMissingPath('meta.hidden');
})->with(['has-one' => ['has-one/profile'], 'morph-one' => ['morph-one/comment']]);

it('hides a has-one-through card whose record the user may not view, and shows a viewable one', function () {
    $team = RCVTeamModel::create(['parent_id' => $this->parent->id]);
    $project = RCVProjectModel::create(['title' => 'Private', 'team_id' => $team->id]);

    $this->getJson(rcvCard('has-one/project'))->assertOk()->assertJsonPath('meta.hidden', true);
    $this->deleteJson(rcvCard('has-one/project'))->assertStatus(404);
    expect($project->fresh())->not->toBeNull();

    $project->update(['title' => 'Visible']);
    $this->getJson(rcvCard('has-one/project'))->assertOk()->assertJsonPath('data.id', $project->id);
});

it('refuses a second record on a has-one or morph-one card, even when the one there is hidden from the user', function (string $path, string $relation, string $kind) {
    $this->parent->{$relation}()->create(['title' => 'Private']);

    $this->postJson(rcvCard($path), ['title' => 'Second'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.message', "The {$kind} relationship has already been filled.");

    expect($this->parent->{$relation}()->count())->toBe(1);
})->with([
    'has-one' => ['has-one/profile', 'notes', 'HasOne'],
    'morph-one' => ['morph-one/comment', 'comments', 'MorphOne'],
]);

it('takes another record on a one-of-many card, which sits on a many relationship, as in Nova', function (string $path, string $relation) {
    $this->parent->{$relation}()->create(['title' => 'First', 'written_at' => now()->subDay()]);

    $this->postJson(rcvCard($path), ['title' => 'Second'])->assertStatus(201);

    expect($this->parent->{$relation}()->count())->toBe(2);
})->with([
    'has-one of many' => ['has-one/notes', 'notes'],
    'morph-one of many' => ['morph-one/comments', 'comments'],
]);

it('refuses a second morph-one record with its reason as the message, also when one is added between the check and the insert', function () {
    $this->parent->comment()->create(['title' => 'First']);

    $this->postJson(rcvCard('morph-one/comment'), ['title' => 'Second'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The MorphOne relationship has already been filled.');

    $this->parent->comments()->delete();
    // A record another request adds after the first check: the check again
    // under the parent's lock refuses the insert.
    $added = false;
    DB::listen(function ($query) use (&$added) {
        if (! $added && str_contains(strtolower($query->sql), 'exists') && str_contains($query->sql, 'rcv_comments')) {
            $added = true;
            DB::table('rcv_comments')->insert(['commentable_type' => RCVParentModel::class, 'commentable_id' => test()->parent->id, 'title' => 'Concurrent', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->postJson(rcvCard('morph-one/comment'), ['title' => 'Mine'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The MorphOne relationship has already been filled.');

    expect($this->parent->comments()->pluck('title')->all())->toBe(['Concurrent']);
});

it('counts "1 of N" by key, so a hook that groups still counts every record', function (string $path, string $relation) {
    RCVRelatedResource::$groupById = true;
    try {
        $this->parent->{$relation}()->create(['title' => 'One', 'written_at' => now()->subHour()]);
        $this->parent->{$relation}()->create(['title' => 'Two', 'written_at' => now()]);

        expect($this->getJson(rcvCard($path))->assertOk()->json('meta.ofMany.totalCount'))->toBe(2);
    } finally {
        RCVRelatedResource::$groupById = false;
    }
})->with([
    'has-one of many' => ['has-one/notes', 'notes'],
    'morph-one of many' => ['morph-one/comments', 'comments'],
]);
