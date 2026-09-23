<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// `attachAny{Model}` is the parent-level attach ability (authorizedToAttachAny()):
// when it denies, the user may attach no record of that model to the parent.
// The attach, the list of records to attach and the attach modal's pivot
// pickers answer 403; `attach{Model}` still decides per record when it
// allows. The attach and the attachable list used to ignore it.

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class AagProject extends Model
{
    protected $table = 'aag_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentBelongsToMany
    {
        return $this->belongsToMany(AagMember::class, 'aag_project_member', 'project_id', 'member_id')->withPivot('note');
    }

    public function tags(): EloquentMorphToMany
    {
        return $this->morphToMany(AagTag::class, 'taggable', 'aag_taggables', 'taggable_id', 'tag_id')->withPivot('note');
    }
}

class AagMember extends Model
{
    protected $table = 'aag_members';

    protected $guarded = [];

    public $timestamps = false;
}

class AagTag extends Model
{
    protected $table = 'aag_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class AagMemberResource extends Resource
{
    public static function model(): string
    {
        return AagMember::class;
    }

    public static function uriKey(): string
    {
        return 'aag-members';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AagTagResource extends Resource
{
    public static function model(): string
    {
        return AagTag::class;
    }

    public static function uriKey(): string
    {
        return 'aag-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AagProjectResource extends Resource
{
    public static function model(): string
    {
        return AagProject::class;
    }

    public static function uriKey(): string
    {
        return 'aag-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            BelongsToMany::make('Members', 'members')
                ->relatedResource('aag-members')
                ->fields(fn () => [Text::make('note')->nullable()]),
            MorphToMany::make('Tags', 'tags')
                ->relatedResource('aag-tags')
                ->fields(fn () => [Text::make('note')->nullable()]),
        ];
    }
}

class AagProjectPolicy
{
    public static bool $attachAny = true;

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Model $project): bool
    {
        return true;
    }

    public function update(?User $user, Model $project): bool
    {
        return true;
    }

    public function attachAnyAagMember(User $user, Model $project): bool
    {
        return self::$attachAny;
    }

    public function attachAnyAagTag(User $user, Model $project): bool
    {
        return self::$attachAny;
    }

    public function attachAagMember(User $user, Model $project, Model $member): bool
    {
        return $member->getAttribute('name') !== 'Blocked';
    }

    public function attachAagTag(User $user, Model $project, Model $tag): bool
    {
        return $tag->getAttribute('name') !== 'Blocked';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['aag_projects', 'aag_members', 'aag_tags'] as $name) {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    Schema::create('aag_project_member', function (Blueprint $table) {
        $table->foreignId('project_id');
        $table->foreignId('member_id');
        $table->string('note')->nullable();
    });

    Schema::create('aag_taggables', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tag_id');
        $table->morphs('taggable');
        $table->string('note')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([AagProjectResource::class, AagMemberResource::class, AagTagResource::class] as $resource) {
        $registry->register($resource);
    }

    Gate::policy(AagProject::class, AagProjectPolicy::class);
    $this->actingAs((new User)->forceFill(['id' => 7]));

    $this->project = AagProject::create(['name' => 'Apollo']);
});

afterEach(function () {
    AagProjectPolicy::$attachAny = true;

    foreach (['aag_taggables', 'aag_project_member', 'aag_tags', 'aag_members', 'aag_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    app(ResourceRegistry::class)->flush();
});

dataset('aag panels', [
    'BelongsToMany' => ['belongs-to-many', 'members', AagMember::class],
    'MorphToMany' => ['morph-to-many', 'tags', AagTag::class],
]);

function aagPanel(AagProject $project, string $segment, string $relationship): string
{
    return "/martis/api/resources/aag-projects/{$project->id}/{$segment}/{$relationship}";
}

/** @param class-string<Model> $model */
function aagRecord(string $model, string $name): Model
{
    return $model::query()->create(['name' => $name]);
}

// ---------------------------------------------------------------------------
// attachAny{Model} denies
// ---------------------------------------------------------------------------

it('refuses to attach one record or several when attachAny{Model} denies', function (string $segment, string $relationship, string $model) {
    $ann = aagRecord($model, 'Ann');
    $bob = aagRecord($model, 'Bob');
    AagProjectPolicy::$attachAny = false;

    $this->postJson(aagPanel($this->project, $segment, $relationship).'/attach', ['related_id' => $ann->getKey()])->assertForbidden();
    $this->postJson(aagPanel($this->project, $segment, $relationship).'/attach', ['related_ids' => [$ann->getKey(), $bob->getKey()]])->assertForbidden();

    expect($this->project->{$relationship}()->count())->toBe(0);
})->with('aag panels');

it('refuses to list the records to attach when attachAny{Model} denies', function (string $segment, string $relationship, string $model) {
    aagRecord($model, 'Ann');

    $this->getJson(aagPanel($this->project, $segment, $relationship).'/attachable')->assertOk()->assertJsonCount(1, 'data');

    AagProjectPolicy::$attachAny = false;

    $this->getJson(aagPanel($this->project, $segment, $relationship).'/attachable')->assertForbidden();
})->with('aag panels');

it('keeps the detach and the pivot update out of the attach gate', function (string $segment, string $relationship, string $model) {
    $ann = aagRecord($model, 'Ann');
    $bob = aagRecord($model, 'Bob');
    $this->project->{$relationship}()->attach([$ann->getKey(), $bob->getKey()]);
    AagProjectPolicy::$attachAny = false;

    $this->putJson(aagPanel($this->project, $segment, $relationship)."/{$ann->getKey()}/pivot", ['note' => 'kept'])->assertOk();
    $this->deleteJson(aagPanel($this->project, $segment, $relationship)."/{$bob->getKey()}/detach")->assertOk();

    expect($this->project->{$relationship}()->pluck('note')->all())->toBe(['kept']);
})->with('aag panels');

// ---------------------------------------------------------------------------
// attachAny{Model} allows: attach{Model} decides per record
// ---------------------------------------------------------------------------

it('still asks attach{Model} for each record when attachAny{Model} allows', function (string $segment, string $relationship, string $model) {
    $ann = aagRecord($model, 'Ann');
    $blocked = aagRecord($model, 'Blocked');

    $this->postJson(aagPanel($this->project, $segment, $relationship).'/attach', ['related_id' => $blocked->getKey()])->assertForbidden();

    $batch = $this->postJson(aagPanel($this->project, $segment, $relationship).'/attach', ['related_ids' => [$ann->getKey(), $blocked->getKey()]]);

    $batch->assertCreated()->assertJsonPath('data.count', 1);
    expect($this->project->{$relationship}()->pluck('name')->all())->toBe(['Ann']);
})->with('aag panels');
