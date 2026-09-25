<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasOne;
use Martis\Fields\MorphOne;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A HasOneOfMany / MorphOneOfMany card shows one record of a many relation.
 * With latestByTimestamp() / oldestByTimestamp() the controller picks it with
 * a runtime order on that relation, and the card's Edit and Delete must write
 * the same record. They used `$relation->first()`, the relation's first row in
 * storage order, so a card showing the newest note renamed or deleted the
 * oldest one. The rows of each case are inserted so that the first stored row
 * is never the one the card shows; the Eloquent latestOfMany() / ofMany()
 * relations, already narrowed to their record, are the controls.
 */

class OMWParentModel extends Model
{
    protected $table = 'omw_parents';

    protected $fillable = ['name'];

    public function notes(): EloquentHasMany
    {
        return $this->hasMany(OMWNoteModel::class, 'parent_id');
    }

    public function latestNote(): EloquentHasOne
    {
        return $this->hasOne(OMWNoteModel::class, 'parent_id')->latestOfMany('written_at');
    }

    public function biggestNote(): EloquentHasOne
    {
        return $this->hasOne(OMWNoteModel::class, 'parent_id')->ofMany('size', 'max');
    }

    public function project(): EloquentHasOneThrough
    {
        return $this->hasOneThrough(OMWProjectModel::class, OMWTeamModel::class, 'parent_id', 'team_id');
    }

    public function comments(): EloquentMorphMany
    {
        return $this->morphMany(OMWCommentModel::class, 'commentable');
    }

    public function latestComment(): EloquentMorphOne
    {
        return $this->morphOne(OMWCommentModel::class, 'commentable')->latestOfMany('written_at');
    }
}

class OMWNoteModel extends Model
{
    protected $table = 'omw_notes';

    protected $fillable = ['title', 'size', 'written_at', 'parent_id'];
}

class OMWCommentModel extends Model
{
    protected $table = 'omw_comments';

    protected $fillable = ['title', 'size', 'written_at', 'commentable_type', 'commentable_id'];
}

class OMWTeamModel extends Model
{
    protected $table = 'omw_teams';

    protected $fillable = ['parent_id'];
}

class OMWProjectModel extends Model
{
    protected $table = 'omw_projects';

    protected $fillable = ['title', 'written_at', 'team_id'];
}

class OMWProjectResource extends Resource
{
    public static function model(): string
    {
        return OMWProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-projects';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

class OMWThroughParentResource extends Resource
{
    public static function model(): string
    {
        return OMWParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-through-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::ofMany('Latest project', 'project', OMWProjectResource::class)->latestByTimestamp('written_at'),
        ];
    }
}

class OMWDenyNewNotePolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function update(mixed $user, OMWNoteModel $note): bool
    {
        return $note->title !== 'New';
    }

    public function delete(mixed $user, OMWNoteModel $note): bool
    {
        return $note->title !== 'New';
    }
}

class OMWNoteResource extends Resource
{
    public static function model(): string
    {
        return OMWNoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-notes';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

class OMWCommentResource extends Resource
{
    public static function model(): string
    {
        return OMWCommentModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-comments';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->required()];
    }
}

class OMWLatestParentResource extends Resource
{
    public static function model(): string
    {
        return OMWParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-latest-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::ofMany('Latest note', 'notes', OMWNoteResource::class)->latestByTimestamp('written_at'),
            MorphOne::ofMany('Latest comment', 'comments', OMWCommentResource::class)->latestByTimestamp('written_at'),
        ];
    }
}

class OMWOldestParentResource extends Resource
{
    public static function model(): string
    {
        return OMWParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-oldest-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::ofMany('Oldest note', 'notes', OMWNoteResource::class)->oldestByTimestamp('written_at'),
            MorphOne::ofMany('Oldest comment', 'comments', OMWCommentResource::class)->oldestByTimestamp('written_at'),
        ];
    }
}

class OMWEloquentParentResource extends Resource
{
    public static function model(): string
    {
        return OMWParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'omw-eloquent-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasOne::ofMany('Latest note', 'latestNote', OMWNoteResource::class),
            HasOne::ofMany('Biggest note', 'biggestNote', OMWNoteResource::class),
            MorphOne::ofMany('Latest comment', 'latestComment', OMWCommentResource::class),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['omw_projects', 'omw_teams', 'omw_comments', 'omw_notes', 'omw_parents'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('omw_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('omw_notes', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->string('title');
        $table->integer('size');
        $table->dateTime('written_at');
        $table->timestamps();
    });

    Schema::create('omw_comments', function ($table) {
        $table->id();
        $table->morphs('commentable');
        $table->string('title');
        $table->integer('size');
        $table->dateTime('written_at');
        $table->timestamps();
    });

    Schema::create('omw_teams', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->timestamps();
    });

    Schema::create('omw_projects', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('title');
        $table->dateTime('written_at');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([OMWNoteResource::class, OMWCommentResource::class, OMWProjectResource::class, OMWLatestParentResource::class, OMWOldestParentResource::class, OMWEloquentParentResource::class, OMWThroughParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->parent = OMWParentModel::create(['name' => 'Parent']);
});

afterEach(function () {
    foreach (['omw_projects', 'omw_teams', 'omw_comments', 'omw_notes', 'omw_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

/**
 * Store `$rows` ([title, written_at, size], in insertion order) under the
 * parent in `$storage` ('notes' or 'comments').
 *
 * @param  list<array{string, string, int}>  $rows
 */
function omwSeed(OMWParentModel $parent, string $storage, array $rows): void
{
    foreach ($rows as [$title, $writtenAt, $size]) {
        $parent->{$storage}()->create(['title' => $title, 'written_at' => $writtenAt, 'size' => $size]);
    }
}

/** @return list<string> the stored titles, in id order */
function omwTitles(string $storage): array
{
    return DB::table($storage === 'notes' ? 'omw_notes' : 'omw_comments')->orderBy('id')->pluck('title')->all();
}

$older = '2026-01-01 10:00:00';
$newer = '2026-06-01 10:00:00';

dataset('omw cards', [
    'HasOneOfMany latestByTimestamp' => ['omw-latest-parents', 'has-one/notes', 'notes', [['Old', $older, 1], ['New', $newer, 2]], 'New'],
    'HasOneOfMany oldestByTimestamp' => ['omw-oldest-parents', 'has-one/notes', 'notes', [['New', $newer, 1], ['Old', $older, 2]], 'Old'],
    'MorphOneOfMany latestByTimestamp' => ['omw-latest-parents', 'morph-one/comments', 'comments', [['Old', $older, 1], ['New', $newer, 2]], 'New'],
    'MorphOneOfMany oldestByTimestamp' => ['omw-oldest-parents', 'morph-one/comments', 'comments', [['New', $newer, 1], ['Old', $older, 2]], 'Old'],
    'HasOneOfMany on latestOfMany() (control)' => ['omw-eloquent-parents', 'has-one/latestNote', 'notes', [['Old', $older, 1], ['New', $newer, 2]], 'New'],
    'HasOneOfMany on ofMany(size, max) (control)' => ['omw-eloquent-parents', 'has-one/biggestNote', 'notes', [['Small', $older, 1], ['Big', $newer, 9]], 'Big'],
    'MorphOneOfMany on latestOfMany() (control)' => ['omw-eloquent-parents', 'morph-one/latestComment', 'comments', [['Old', $older, 1], ['New', $newer, 2]], 'New'],
]);

it('updates the record the card shows', function (string $resource, string $path, string $storage, array $rows, string $shown) {
    omwSeed($this->parent, $storage, $rows);
    $url = "/martis/api/resources/{$resource}/{$this->parent->id}/{$path}";

    $this->getJson($url)->assertStatus(200)->assertJsonPath('data.title', $shown);
    $this->putJson($url, ['title' => 'Renamed'])->assertStatus(200)->assertJsonPath('data.title', 'Renamed');

    $expected = array_map(fn (array $row) => $row[0] === $shown ? 'Renamed' : $row[0], $rows);
    expect(omwTitles($storage))->toBe($expected);
})->with('omw cards');

it('deletes the record the card shows', function (string $resource, string $path, string $storage, array $rows, string $shown) {
    omwSeed($this->parent, $storage, $rows);
    $url = "/martis/api/resources/{$resource}/{$this->parent->id}/{$path}";

    $this->getJson($url)->assertStatus(200)->assertJsonPath('data.title', $shown);
    $this->deleteJson($url)->assertStatus(200);

    $expected = array_values(array_filter(array_map(fn (array $row) => $row[0], $rows), fn (string $title) => $title !== $shown));
    expect(omwTitles($storage))->toBe($expected);
})->with('omw cards');

/**
 * A through relation joins the intermediate table, so a raw `select *` lets
 * the intermediate's id overwrite the related record's id. The teams and
 * projects below are numbered so that parent A's team id (2) is the id of
 * one of parent B's projects: a write that trusted the joined id would land
 * on parent B's record.
 */
function omwSeedThrough(OMWParentModel $a, string $older, string $newer): OMWParentModel
{
    $b = OMWParentModel::create(['name' => 'B']);
    $teamB = OMWTeamModel::create(['parent_id' => $b->id]);
    OMWProjectModel::create(['team_id' => $teamB->id, 'title' => 'B-One', 'written_at' => $older]);
    OMWProjectModel::create(['team_id' => $teamB->id, 'title' => 'B-Two', 'written_at' => $older]);
    $teamA = OMWTeamModel::create(['parent_id' => $a->id]);
    OMWProjectModel::create(['team_id' => $teamA->id, 'title' => 'A-Old', 'written_at' => $older]);
    OMWProjectModel::create(['team_id' => $teamA->id, 'title' => 'A-New', 'written_at' => $newer]);

    expect($teamA->id)->toBe(2);

    return $b;
}

it('shows, updates and deletes the record of a one-of-many declared on a through relation, never another parent\'s', function () use ($older, $newer) {
    omwSeedThrough($this->parent, $older, $newer);
    $aNew = OMWProjectModel::query()->where('title', 'A-New')->value('id');
    $url = "/martis/api/resources/omw-through-parents/{$this->parent->id}/has-one/project";

    $this->getJson($url)->assertStatus(200)
        ->assertJsonPath('data.title', 'A-New')
        ->assertJsonPath('data.id', $aNew);

    $this->putJson($url, ['title' => 'Renamed'])->assertStatus(200);
    expect(DB::table('omw_projects')->orderBy('id')->pluck('title')->all())->toBe(['B-One', 'B-Two', 'A-Old', 'Renamed']);

    $this->deleteJson($url)->assertStatus(200);
    expect(DB::table('omw_projects')->orderBy('id')->pluck('title')->all())->toBe(['B-One', 'B-Two', 'A-Old']);
});

it('checks the policy on the record the card shows', function () use ($older, $newer) {
    Gate::policy(OMWNoteModel::class, OMWDenyNewNotePolicy::class);
    omwSeed($this->parent, 'notes', [['Old', $older, 1], ['New', $newer, 2]]);
    $url = "/martis/api/resources/omw-latest-parents/{$this->parent->id}/has-one/notes";

    $this->putJson($url, ['title' => 'Renamed'])->assertStatus(403);
    $this->deleteJson($url)->assertStatus(403);

    expect(omwTitles('notes'))->toBe(['Old', 'New']);
});

it('breaks a timestamp tie on the primary key, the same way for show, update and delete', function (string $resource, string $shown) use ($older) {
    omwSeed($this->parent, 'notes', [['First', $older, 1], ['Second', $older, 2]]);
    $url = "/martis/api/resources/{$resource}/{$this->parent->id}/has-one/notes";

    $this->getJson($url)->assertStatus(200)->assertJsonPath('data.title', $shown);
    $this->putJson($url, ['title' => 'Renamed'])->assertStatus(200);
    expect(omwTitles('notes'))->toBe(array_map(fn (string $t) => $t === $shown ? 'Renamed' : $t, ['First', 'Second']));
})->with([
    'latest keeps the newest id' => ['omw-latest-parents', 'Second'],
    'oldest keeps the oldest id' => ['omw-oldest-parents', 'First'],
]);
