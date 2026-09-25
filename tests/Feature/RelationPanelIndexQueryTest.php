<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasManyThrough;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A relationship panel lists the records of another resource, so it hides
 * what that resource's index hides: its declarative `scopes()` and its
 * `indexQuery()` (tenancy, visibility) apply to the panel's rows too, in
 * the index's order, as Nova runs the related resource's `indexQuery()` on
 * a relationship index. Each related table has a row each hook hides; the
 * joined tables (the through and pivot tables) carry a `title` column too,
 * so a hook column left unqualified would be ambiguous there.
 */

class RPITeamModel extends Model
{
    protected $table = 'rpi_teams';

    protected $fillable = ['title'];

    public function members(): EloquentHasMany
    {
        return $this->hasMany(RPIMemberModel::class, 'team_id');
    }

    public function freshMembers(): EloquentHasMany
    {
        return $this->hasMany(RPIMemberModel::class, 'team_id');
    }

    public function groupMembers(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(RPIMemberModel::class, RPIGroupModel::class, 'team_id', 'group_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RPINoteModel::class, 'notable');
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RPITagModel::class, 'rpi_team_tag', 'team_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(RPITagModel::class, 'taggable', 'rpi_taggables', 'taggable_id', 'tag_id');
    }
}

class RPIGroupModel extends Model
{
    protected $table = 'rpi_groups';

    protected $fillable = ['title', 'team_id'];
}

class RPIMemberModel extends Model
{
    protected $table = 'rpi_members';

    protected $fillable = ['title', 'team_id', 'group_id'];
}

class RPINoteModel extends Model
{
    protected $table = 'rpi_notes';

    protected $fillable = ['title', 'notable_type', 'notable_id'];
}

class RPITagModel extends Model
{
    use SoftDeletes;

    protected $table = 'rpi_tags';

    protected $fillable = ['title'];
}

/** Hides "By index" through indexQuery() and "By scope" through scopes(). */
abstract class RPIHidingResource extends Resource
{
    public static bool $unqualified = false;

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where(static::$unqualified ? 'title' : $query->qualifyColumn('title'), '!=', 'By index');
    }

    public static function scopes(Request $request): array
    {
        return ['visible' => fn (Builder $query) => $query->where($query->qualifyColumn('title'), '!=', 'By scope')];
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')->searchable()];
    }
}

class RPIMemberResource extends RPIHidingResource
{
    public static function model(): string
    {
        return RPIMemberModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpi-members';
    }
}

// An indexQuery() that builds its own query instead of narrowing the one
// it receives: the panel constrains its rows by key.
class RPIFreshMemberResource extends RPIMemberResource
{
    public static function uriKey(): string
    {
        return 'rpi-fresh-members';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return RPIMemberModel::query()->where('title', '!=', 'By index');
    }
}

class RPINoteResource extends RPIHidingResource
{
    public static function model(): string
    {
        return RPINoteModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpi-notes';
    }
}

class RPITagResource extends RPIHidingResource
{
    public static function model(): string
    {
        return RPITagModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpi-tags';
    }
}

class RPITeamResource extends Resource
{
    public static function model(): string
    {
        return RPITeamModel::class;
    }

    public static function uriKey(): string
    {
        return 'rpi-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            HasMany::make('Members', 'members')->relatedResource('rpi-members'),
            HasMany::make('Fresh members', 'freshMembers')->relatedResource('rpi-fresh-members'),
            HasManyThrough::make('Group members', 'groupMembers')->relatedResource('rpi-members'),
            MorphMany::make('Notes', 'notes')->relatedResource('rpi-notes'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('rpi-tags'),
            MorphToMany::make('Labels', 'labels')->relatedResource('rpi-tags'),
        ];
    }
}

// The same teams, listing their relationship counts on the index.
class RPICountedTeamResource extends RPITeamResource
{
    public static function uriKey(): string
    {
        return 'rpi-counted-teams';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            HasMany::make('Members', 'members')->relatedResource('rpi-members')->showOnIndex(),
            HasManyThrough::make('Group members', 'groupMembers')->relatedResource('rpi-members')->showOnIndex(),
            MorphMany::make('Notes', 'notes')->relatedResource('rpi-notes')->showOnIndex(),
            BelongsToMany::make('Tags', 'tags')->relatedResource('rpi-tags')->showOnIndex(),
            MorphToMany::make('Labels', 'labels')->relatedResource('rpi-tags')->showOnIndex(),
        ];
    }
}

const RPI_TABLES = ['rpi_taggables', 'rpi_team_tag', 'rpi_tags', 'rpi_notes', 'rpi_members', 'rpi_groups', 'rpi_teams'];

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (RPI_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rpi_teams', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('rpi_groups', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('team_id');
        $table->timestamps();
    });
    Schema::create('rpi_members', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('group_id');
        $table->timestamps();
    });
    Schema::create('rpi_notes', function ($table) {
        $table->id();
        $table->string('title');
        $table->morphs('notable');
        $table->timestamps();
    });
    Schema::create('rpi_tags', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('rpi_team_tag', function ($table) {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('tag_id');
        $table->string('title')->nullable();
    });
    Schema::create('rpi_taggables', function ($table) {
        $table->unsignedBigInteger('tag_id');
        $table->morphs('taggable');
        $table->string('title')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RPIMemberResource::class, RPIFreshMemberResource::class, RPINoteResource::class, RPITagResource::class, RPITeamResource::class, RPICountedTeamResource::class] as $class) {
        $registry->register($class);
    }

    $this->team = RPITeamModel::create(['title' => 'Team']);
    $group = RPIGroupModel::create(['title' => 'Group', 'team_id' => $this->team->id]);

    foreach (['Visible', 'By index', 'By scope'] as $title) {
        RPIMemberModel::create(['title' => $title, 'team_id' => $this->team->id, 'group_id' => $group->id]);
        $this->team->notes()->create(['title' => $title]);
        $tag = RPITagModel::create(['title' => $title]);
        $this->team->tags()->attach($tag->id, ['title' => 'pivot']);
        $this->team->labels()->attach($tag->id, ['title' => 'pivot']);
    }

    $trashed = RPITagModel::create(['title' => 'Trashed']);
    $this->team->tags()->attach($trashed->id);
    $this->team->labels()->attach($trashed->id);
    $trashed->delete();
});

afterEach(function () {
    foreach (RPI_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

function rpiTitles(string $path, string $query = ''): array
{
    return collect(test()->getJson('/martis/api/resources/rpi-teams/'.test()->team->id.'/'.$path.$query)->assertOk()->json('data'))
        ->pluck('title')
        ->sort()
        ->values()
        ->all();
}

dataset('rpi panels', [
    'has-many' => ['has-many/members'],
    'has-many, an indexQuery() returning its own query' => ['has-many/freshMembers'],
    'has-many-through' => ['has-many/groupMembers'],
    'morph-many' => ['morph-many/notes'],
    'belongs-to-many' => ['belongs-to-many/tags'],
    'morph-to-many' => ['morph-to-many/labels'],
]);

it('hides from a relationship panel the rows the related resource\'s indexQuery() and scopes() hide', function (string $path) {
    expect(rpiTitles($path))->toBe(['Visible']);
})->with('rpi panels');

it('keeps the hidden rows out of a panel\'s count and its search', function (string $path) {
    $response = test()->getJson('/martis/api/resources/rpi-teams/'.test()->team->id.'/'.$path.'?search=By')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);
})->with('rpi panels');

it('applies the trashed filter on a pivot panel, which listed the active records for every value', function (string $path) {
    expect(rpiTitles($path))->toBe(['Visible'])
        ->and(rpiTitles($path, '?trashed=only'))->toBe(['Trashed'])
        ->and(rpiTitles($path, '?trashed=with'))->toBe(['Trashed', 'Visible']);
})->with([
    'belongs-to-many' => ['belongs-to-many/tags'],
    'morph-to-many' => ['morph-to-many/labels'],
]);

it('counts on the index only the related records the related index lists, in the listing query', function () {
    RPITeamModel::create(['title' => 'Empty team']);
    DB::enableQueryLog();

    $row = collect($this->getJson('/martis/api/resources/rpi-counted-teams')->assertOk()->json('data'))->firstWhere('title', 'Team');

    expect([$row['members'], $row['groupMembers'], $row['notes'], $row['tags'], $row['labels']])->toBe([1, 1, 1, 1, 1]);

    // No count query per row: the counts come with the page.
    $perRow = collect(DB::getQueryLog())->filter(fn (array $q) => str_starts_with(strtolower((string) $q['query']), 'select count(*) as aggregate from') && ! str_contains((string) $q['query'], 'rpi_teams'));
    expect($perRow)->toBeEmpty();
});

it('scopes a pivot panel by key, so a hook column the pivot also has stays unambiguous', function (string $path) {
    // rpi_team_tag and rpi_taggables carry a `title` too: run on the pivot
    // panel's own query, an unqualified `title` would be ambiguous (500).
    RPIHidingResource::$unqualified = true;
    try {
        expect(rpiTitles($path))->toBe(['Visible']);
    } finally {
        RPIHidingResource::$unqualified = false;
    }
})->with([
    'belongs-to-many' => ['belongs-to-many/tags'],
    'morph-to-many' => ['morph-to-many/labels'],
]);
