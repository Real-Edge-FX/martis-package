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
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
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

/**
 * Hides "By index" from the index; the "Private" record may not be viewed,
 * the "Locked" one may not be updated or deleted.
 */
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

    public function authorizedToUpdate(Request $request): bool
    {
        return $this->model?->getAttribute('title') !== 'Locked';
    }

    public function authorizedToDelete(Request $request): bool
    {
        return $this->model?->getAttribute('title') !== 'Locked';
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
    $this->putJson(cardWriteUrl(rcvCard($path)), ['title' => 'Renamed'])->assertStatus(404);
    $this->deleteJson(cardWriteUrl(rcvCard($path)))->assertStatus(404);

    expect($record->fresh()?->title)->toBe('Private');
})->with('rcv cards');

it('answers 404, not the id check, to a write on a card whose record the user may not view', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Private', 'written_at' => now()]);

    // Neither a made-up id nor the record's own tells the user it exists.
    foreach (['999', (string) $record->id] as $relatedId) {
        $this->putJson(rcvCard($path).'?relatedId='.$relatedId, ['title' => 'Renamed'])->assertStatus(404);
        $this->deleteJson(rcvCard($path).'?relatedId='.$relatedId)->assertStatus(404);
    }

    expect($record->fresh()?->title)->toBe('Private');
})->with('rcv cards');

it('answers 403 to a denied user whether or not the card names the record, and 409 to a stale id before the policy', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Locked', 'written_at' => now()]);

    // Without the id (422 otherwise) and with its own: the policy answers.
    foreach (['', '?relatedId='.$record->id] as $query) {
        $this->putJson(rcvCard($path).$query, ['title' => 'Renamed'])->assertStatus(403);
        $this->deleteJson(rcvCard($path).$query)->assertStatus(403);
    }
    // Another id: the request was for another record, and the policy is
    // this one's, so the answer is the conflict.
    $this->putJson(rcvCard($path).'?relatedId=999', ['title' => 'Renamed'])->assertStatus(409);
    $this->deleteJson(rcvCard($path).'?relatedId=999')->assertStatus(409);

    expect($record->fresh()?->title)->toBe('Locked');
})->with('rcv cards');

it('answers 409, not the new record\'s 403, once a record the user may not write took the shown one\'s place', function (string $path, string $relation, string $change, string $method) {
    $shown = $this->parent->{$relation}()->create(['title' => 'Shown', 'written_at' => now()->subHour()]);
    $url = cardWriteUrl(rcvCard($path));

    if ($change === 'replace') {
        $shown->delete();
    }
    $locked = $this->parent->{$relation}()->create(['title' => 'Locked', 'written_at' => now()]);

    $this->{$method}($url, ['title' => 'Written'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The record changed since the card loaded; reload to see it.');

    expect($locked->fresh()->title)->toBe('Locked');
})->with('rcv changed cards')->with(['putJson', 'deleteJson']);

it('answers the id check in the app\'s locale', function () {
    $record = $this->parent->notes()->create(['title' => 'Shown', 'written_at' => now()]);
    // The preferences middleware would put the configured default back.
    $this->withoutMiddleware(ApplyUserPreferencesLocale::class);
    app()->setLocale('pt_PT');

    $this->deleteJson(rcvCard('has-one/profile'))->assertStatus(422)
        ->assertJsonPath('message', 'O id do registo que o cartão mostra é obrigatório (relatedId).');
    $this->deleteJson(rcvCard('has-one/profile').'?relatedId=999')->assertStatus(409)
        ->assertJsonPath('message', 'O registo mudou desde que o cartão carregou; recarregue para o ver.');

    expect($record->fresh())->not->toBeNull();
});

it('shows and writes a record the user may view (control)', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Visible', 'written_at' => now()]);

    $this->getJson(rcvCard($path))->assertOk()
        ->assertJsonPath('data.id', $record->id)
        ->assertJsonMissingPath('meta.hidden');
    $this->putJson(cardWriteUrl(rcvCard($path)), ['title' => 'Renamed'])->assertOk();

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
    $this->deleteJson(cardWriteUrl(rcvCard('has-one/project')))->assertStatus(404);
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

/*
 * A write on a card names the record the card shows (`?relatedId=`). A
 * record created or swapped in between the load and the click (a newer
 * one-of-many record, a replaced HasOne, another Through record) would
 * otherwise be the one written: the write answers 409 and touches nothing.
 */
dataset('rcv changed cards', [
    'has-one, replaced' => ['has-one/profile', 'notes', 'replace'],
    'morph-one, replaced' => ['morph-one/comment', 'comments', 'replace'],
    'has-one of many, a newer record' => ['has-one/notes', 'notes', 'newer'],
    'morph-one of many, a newer record' => ['morph-one/comments', 'comments', 'newer'],
    'has-one of many, Eloquent latestOfMany(), a newer record' => ['has-one/newestNote', 'notes', 'newer'],
]);

it('refuses a write aimed at the record the card showed once another one took its place', function (string $path, string $relation, string $change, string $method) {
    $shown = $this->parent->{$relation}()->create(['title' => 'Shown', 'written_at' => now()->subHour()]);
    $url = cardWriteUrl(rcvCard($path));

    if ($change === 'replace') {
        $shown->delete();
    }
    $current = $this->parent->{$relation}()->create(['title' => 'Current', 'written_at' => now()]);

    $this->{$method}($url, ['title' => 'Written'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The record changed since the card loaded; reload to see it.');

    expect($current->fresh()->title)->toBe('Current');
    if ($change === 'newer') {
        expect($shown->fresh()->title)->toBe('Shown');
    }
})->with('rcv changed cards')->with(['putJson', 'deleteJson']);

it('refuses a has-one-through write once another record took the shown one\'s place', function (string $method) {
    $team = RCVTeamModel::create(['parent_id' => $this->parent->id]);
    $shown = RCVProjectModel::create(['title' => 'Shown', 'team_id' => $team->id]);
    $url = cardWriteUrl(rcvCard('has-one/project'));
    $shown->delete();
    $current = RCVProjectModel::create(['title' => 'Current', 'team_id' => $team->id]);

    $this->{$method}($url, ['title' => 'Written'])->assertStatus(409);

    expect($current->fresh()->title)->toBe('Current');
})->with(['putJson', 'deleteJson']);

it('writes the shown record when the card names it, and needs the name', function (string $path, string $relation) {
    $record = $this->parent->{$relation}()->create(['title' => 'Shown', 'written_at' => now()]);

    $this->putJson(rcvCard($path), ['title' => 'Written'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'relatedId');
    $this->deleteJson(rcvCard($path))->assertStatus(422);
    expect($record->fresh()->title)->toBe('Shown');

    $this->putJson(rcvCard($path).'?relatedId='.$record->id, ['title' => 'Written'])->assertOk();
    expect($record->fresh()->title)->toBe('Written');

    $this->deleteJson(rcvCard($path).'?relatedId='.$record->id)->assertOk();
    expect($record->fresh())->toBeNull();
})->with([
    'has-one' => ['has-one/profile', 'notes'],
    'morph-one' => ['morph-one/comment', 'comments'],
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
