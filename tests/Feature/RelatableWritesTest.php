<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\MorphTo;
use Martis\Fields\MorphToMany;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A relationship write must name a record its picker offers (Nova's
// `Relatable` / `RelatableAttachment` rules). The pickers applied the
// target's relatableQuery(), the source's relatable{PluralModelName}() and
// the field's relatableQueryUsing(), but the writes saved any id: a crafted
// request set a BelongsTo / MorphTo to, synced a Tag with, or attached a
// record of another tenant. Every write path now answers 422 on the field.
//
// Users: Ana (tenant 1, active), Bruno (tenant 1, inactive), Carla (tenant 2),
// Dora (tenant 1, active, soft-deleted). The users' relatableQuery() keeps
// tenant 1, and the tasks' relatableRWUsers() keeps the active ones, so the
// task pickers offer Ana only.
// Tags: public (1), private (2); the tags' relatableQuery() keeps public ones.
// ---------------------------------------------------------------------------

class RWUser extends Model
{
    use SoftDeletes;

    protected $table = 'rw_users';

    protected $guarded = [];

    public $timestamps = false;
}

class RWTag extends Model
{
    protected $table = 'rw_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class RWTask extends Model
{
    protected $table = 'rw_tasks';

    protected $guarded = [];

    public $timestamps = false;

    public function owner(): EloquentBelongsTo
    {
        return $this->belongsTo(RWUser::class, 'owner_id');
    }

    public function subject(): EloquentMorphTo
    {
        return $this->morphTo();
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RWTag::class, 'rw_task_tag', 'task_id', 'tag_id');
    }

    public function labels(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RWTag::class, 'rw_task_label', 'task_id', 'tag_id')->withPivot('reviewer_id');
    }

    public function topics(): EloquentMorphToMany
    {
        return $this->morphToMany(RWTag::class, 'taggable', 'rw_taggables', null, 'tag_id');
    }

    public function notes(): EloquentHasMany
    {
        return $this->hasMany(RWNote::class, 'task_id');
    }
}

class RWNote extends Model
{
    protected $table = 'rw_notes';

    protected $guarded = [];

    public $timestamps = false;
}

class RWAuthUser extends Authenticatable
{
    protected $table = 'rw_auth_users';
}

class RWUserResource extends Resource
{
    public static function model(): string
    {
        return RWUser::class;
    }

    public static function uriKey(): string
    {
        return 'rw-users';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('tenant_id', 1);
    }
}

class RWTagResource extends Resource
{
    public static function model(): string
    {
        return RWTag::class;
    }

    public static function uriKey(): string
    {
        return 'rw-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

class RWTaskResource extends Resource
{
    public static function model(): string
    {
        return RWTask::class;
    }

    public static function uriKey(): string
    {
        return 'rw-tasks';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('owner', 'Owner')->relatedResource('rw-users')->nullable(),
            MorphTo::make('subject', 'Subject')->types([RWUserResource::class, RWTagResource::class])->nullable(),
            Tag::make('tags', 'Tags')->relatedResource('rw-tags')->nullable(),
            BelongsToMany::make('Labels', 'labels')
                ->relatedResource('rw-tags')
                ->fields(fn () => [BelongsTo::make('reviewer', 'Reviewer')->relatedResource('rw-users')->nullable()]),
            MorphToMany::make('Topics', 'topics')->relatedResource('rw-tags'),
            HasMany::make('Notes', 'notes')->relatedResource('rw-notes'),
        ];
    }

    public function actions(Request $request): array
    {
        return [new RWAssignAction];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('title', '!=', 'Hidden');
    }

    public static function relatableRWUsers(Request $request, Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

// Same resource, but the owner picker leaves the trashed users out.
class RWStrictTaskResource extends RWTaskResource
{
    public static function uriKey(): string
    {
        return 'rw-strict-tasks';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('owner', 'Owner')->relatedResource('rw-users')->withoutTrashed()->nullable(),
            // A picker that narrows further with its own closure.
            BelongsTo::make('subject', 'Subject')
                ->foreignKey('subject_id')
                ->relatedResource('rw-users')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', '!=', 'Ana'))
                ->nullable(),
            BelongsToMany::make('Labels', 'labels')
                ->relatedResource('rw-tags')
                ->relatableQueryUsing(fn (Request $request, Builder $query, array $form) => $query->where('name', $form['label'] ?? 'public')),
        ];
    }
}

class RWNoteResource extends Resource
{
    public static function model(): string
    {
        return RWNote::class;
    }

    public static function uriKey(): string
    {
        return 'rw-notes';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('body'),
            BelongsTo::make('task', 'Task')->relatedResource('rw-tasks'),
            BelongsTo::make('author', 'Author')->relatedResource('rw-users')->nullable(),
        ];
    }
}

class RWAssignAction extends Action
{
    public function fields(Request $request): array
    {
        return [BelongsTo::make('assignee', 'Assignee')->relatedResource('rw-users')];
    }

    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Assigned.');
    }

    public function uriKey(): string
    {
        return 'rw-assign';
    }
}

// The related users deny adding a task to Bruno... and to nobody else.
class RWUserPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function addRWTask(mixed $user, RWUser $related): bool
    {
        return $related->name !== 'Ana';
    }
}

class RWDenyViewAnyUserPolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}

class RWTaskPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return true;
    }

    public function attachRWTag(mixed $user, RWTask $task, RWTag $tag): bool
    {
        return $tag->name !== 'public';
    }
}

const RW_TABLES = ['rw_taggables', 'rw_task_label', 'rw_task_tag', 'rw_notes', 'rw_tasks', 'rw_tags', 'rw_users', 'rw_auth_users'];

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (RW_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rw_auth_users', function ($t) {
        $t->id();
        $t->string('name')->nullable();
    });
    Schema::create('rw_users', function ($t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('tenant_id');
        $t->boolean('is_active')->default(true);
        $t->softDeletes();
    });
    Schema::create('rw_tags', function ($t) {
        $t->id();
        $t->string('name');
        $t->boolean('is_public')->default(true);
    });
    Schema::create('rw_tasks', function ($t) {
        $t->id();
        $t->string('title');
        $t->unsignedBigInteger('owner_id')->nullable();
        $t->nullableMorphs('subject');
    });
    Schema::create('rw_task_tag', function ($t) {
        $t->id();
        $t->unsignedBigInteger('task_id');
        $t->unsignedBigInteger('tag_id');
    });
    Schema::create('rw_task_label', function ($t) {
        $t->id();
        $t->unsignedBigInteger('task_id');
        $t->unsignedBigInteger('tag_id');
        $t->unsignedBigInteger('reviewer_id')->nullable();
    });
    Schema::create('rw_taggables', function ($t) {
        $t->id();
        $t->unsignedBigInteger('tag_id');
        $t->morphs('taggable');
    });
    Schema::create('rw_notes', function ($t) {
        $t->id();
        $t->string('body');
        $t->unsignedBigInteger('task_id')->nullable();
        $t->unsignedBigInteger('author_id')->nullable();
    });

    RWUser::query()->insert([
        ['id' => 1, 'name' => 'Ana', 'tenant_id' => 1, 'is_active' => true, 'deleted_at' => null],
        ['id' => 2, 'name' => 'Bruno', 'tenant_id' => 1, 'is_active' => false, 'deleted_at' => null],
        ['id' => 3, 'name' => 'Carla', 'tenant_id' => 2, 'is_active' => true, 'deleted_at' => null],
        ['id' => 4, 'name' => 'Dora', 'tenant_id' => 1, 'is_active' => true, 'deleted_at' => now()],
    ]);
    RWTag::query()->insert([
        ['id' => 1, 'name' => 'public', 'is_public' => true],
        ['id' => 2, 'name' => 'private', 'is_public' => false],
    ]);
    $this->task = RWTask::query()->create(['title' => 'Ship']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RWUserResource::class, RWTagResource::class, RWTaskResource::class, RWStrictTaskResource::class, RWNoteResource::class] as $class) {
        $registry->register($class);
    }
});

afterEach(function () {
    foreach (RW_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

function rwRelatableError(string $attribute): string
{
    return "This {$attribute} may not be associated with this resource.";
}

// ---------------------------------------------------------------------------
// BelongsTo: create, update, inline create
// ---------------------------------------------------------------------------

it('rejects a BelongsTo id outside the target relatableQuery() on create', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id')
        ->assertJsonPath('errors.0.message', rwRelatableError('Owner'))
        ->assertJsonPath('errors.0.code', 'relatable');

    expect(RWTask::query()->where('title', 'New')->exists())->toBeFalse();
});

it('rejects a BelongsTo id the source relatable{PluralModelName}() leaves out', function () {
    // Bruno is in tenant 1 but inactive: the task picker never lists him.
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');
});

it('rejects a BelongsTo id the field relatableQueryUsing() leaves out', function () {
    $this->postJson('/martis/api/resources/rw-strict-tasks', ['title' => 'New', 'subject_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'subject_id');
});

it('rejects a missing BelongsTo id and a non-numeric one for an integer key', function (mixed $id) {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => $id])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');
})->with([999, 'abc']);

it('accepts a BelongsTo id the picker offers, as a raw id or an id map', function (mixed $value) {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => $value])
        ->assertCreated();

    expect(RWTask::query()->where('title', 'New')->value('owner_id'))->toBe(1);
})->with([1, '1', [['id' => 1]]]);

it('accepts an empty BelongsTo value', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => null])
        ->assertCreated();
});

it('rejects a BelongsTo id outside the picker on update', function () {
    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}", ['title' => 'Ship', 'owner_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');

    expect($this->task->fresh()->owner_id)->toBeNull();
});

it('keeps accepting the stored BelongsTo id on update after it fell out of the picker', function () {
    $this->task->update(['owner_id' => 3]);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}", ['title' => 'Renamed', 'owner_id' => 3])
        ->assertOk();

    expect($this->task->fresh()->title)->toBe('Renamed');
});

it('rejects a BelongsTo id outside the picker on inline create', function () {
    $this->postJson('/martis/api/resources/rw-tasks/inline-create', ['title' => 'New', 'owner_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');

    $this->postJson('/martis/api/resources/rw-tasks/inline-create', ['title' => 'New', 'owner_id' => 1])
        ->assertSuccessful();
});

it('translates the error', function () {
    // The preferences middleware would put the configured default back.
    $this->withoutMiddleware(ApplyUserPreferencesLocale::class);
    app()->setLocale('pt_PT');

    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.message', 'Este Owner não pode ser associado a este recurso.');

    app()->setLocale('pt_BR');

    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/attach", ['related_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.message', 'Este registro não pode ser vinculado a este recurso.');
});

// ---------------------------------------------------------------------------
// Soft-deleted related records
// ---------------------------------------------------------------------------

it('rejects a soft-deleted BelongsTo target unless the request asks for trashed records', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 4])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');

    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 4, 'owner_id_trashed' => 'true'])
        ->assertCreated();
});

it('ignores the trashed opt-in on a withoutTrashed() field', function () {
    $this->postJson('/martis/api/resources/rw-strict-tasks', ['title' => 'New', 'owner_id' => 4, 'owner_id_trashed' => true])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');
});

// ---------------------------------------------------------------------------
// Policies of the related resource
// ---------------------------------------------------------------------------

it('rejects a BelongsTo target whose add{Model} policy ability denies it', function () {
    $this->actingAs((new RWAuthUser)->forceFill(['id' => 1]));
    Gate::policy(RWUser::class, RWUserPolicy::class);
    Gate::policy(RWTask::class, RWTaskPolicy::class);

    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');
});

it('rejects a BelongsTo target of a resource the user may not list', function () {
    $this->actingAs((new RWAuthUser)->forceFill(['id' => 1]));
    Gate::policy(RWUser::class, RWDenyViewAnyUserPolicy::class);

    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'owner_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'owner_id');
});

// ---------------------------------------------------------------------------
// MorphTo
// ---------------------------------------------------------------------------

it('rejects a MorphTo target outside the picker of its type', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'subject' => ['type' => RWTag::class, 'id' => 2]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'subject');

    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'subject' => ['resourceType' => 'rw-users', 'id' => 3]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'subject');
});

it('accepts a MorphTo target the picker offers, and the stored one on update', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'subject' => ['type' => RWTag::class, 'id' => 1]])
        ->assertCreated();

    $this->task->update(['subject_type' => RWTag::class, 'subject_id' => 2]);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}", ['title' => 'Ship', 'subject' => ['type' => RWTag::class, 'id' => 2]])
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Tag (synced on save)
// ---------------------------------------------------------------------------

it('rejects a Tag id outside the picker on create and syncs nothing', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'tags' => [1, 2]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'tags');

    expect(DB::table('rw_task_tag')->count())->toBe(0);
});

it('accepts the Tag ids the picker offers', function () {
    $this->postJson('/martis/api/resources/rw-tasks', ['title' => 'New', 'tags' => [['id' => 1]]])
        ->assertCreated();

    expect(DB::table('rw_task_tag')->pluck('tag_id')->all())->toBe([1]);
});

it('keeps a Tag already synced on update and rejects a new one outside the picker', function () {
    $this->task->tags()->attach(2);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}", ['title' => 'Ship', 'tags' => [1, 2]])
        ->assertOk();

    $this->task->tags()->detach(2);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}", ['title' => 'Ship', 'tags' => [1, 2]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'tags');
});

it('checks the attach policy of the source resource for a Tag', function () {
    $this->actingAs((new RWAuthUser)->forceFill(['id' => 1]));
    Gate::policy(RWTask::class, RWTaskPolicy::class);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}", ['title' => 'Ship', 'tags' => [1]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'tags');
});

// ---------------------------------------------------------------------------
// Relationship panels: inline HasMany create, attach, pivot update
// ---------------------------------------------------------------------------

it('rejects a BelongsTo id outside the picker in a HasMany create', function () {
    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/has-many/notes", ['body' => 'Hi', 'author_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'author_id');
});

it('does not check the inverse BelongsTo of a HasMany create against its picker', function () {
    // The notes' task picker leaves "Hidden" out, but the note is created
    // under it: the store writes the parent whatever the form sends.
    $hidden = RWTask::query()->create(['title' => 'Hidden']);

    $this->postJson("/martis/api/resources/rw-tasks/{$hidden->id}/has-many/notes", ['body' => 'Hi', 'task_id' => $hidden->id, 'author_id' => 1])
        ->assertCreated();

    expect(RWNote::query()->value('task_id'))->toBe($hidden->id);
});

it('rejects an attach of a record outside the attach picker', function () {
    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/attach", ['related_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'related_id')
        ->assertJsonPath('errors.0.message', 'This record may not be attached to this resource.');

    expect(DB::table('rw_task_label')->count())->toBe(0);

    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/attach", ['related_id' => 1])
        ->assertCreated();
});

it('rejects a batch attach with one record outside the attach picker and attaches none', function () {
    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/attach", ['related_ids' => [1, 2]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'related_ids');

    expect(DB::table('rw_task_label')->count())->toBe(0);
});

it('skips the records a batch attach finds already attached', function () {
    RWTag::query()->insert(['id' => 3, 'name' => 'also public', 'is_public' => true]);
    $this->task->labels()->attach(3);

    // The duplicate check narrowed the relation itself, so after the first
    // record it matched nothing and the batch attached tag 3 a second time.
    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/attach", ['related_ids' => [1, 3]])
        ->assertCreated()
        ->assertJsonPath('data.count', 1);

    expect(DB::table('rw_task_label')->where('tag_id', 3)->count())->toBe(1);
});

it('passes the form draft of the attach to a 3-argument closure', function () {
    $url = "/martis/api/resources/rw-strict-tasks/{$this->task->id}/belongs-to-many/labels/attach";

    // Without a draft the closure keeps "public".
    $this->postJson($url, ['related_id' => 1])->assertCreated();

    RWTag::query()->insert(['id' => 3, 'name' => 'draft', 'is_public' => true]);
    $this->postJson($url, ['related_id' => 3])->assertStatus(422);
    $this->postJson($url.'?form[label]=draft', ['related_id' => 3])->assertCreated();
});

it('rejects a pivot BelongsTo outside the picker on attach', function () {
    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/attach", ['related_id' => 1, 'reviewer_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'reviewer_id');

    expect(DB::table('rw_task_label')->count())->toBe(0);
});

it('rejects a pivot update of a record outside the attach picker', function () {
    $this->task->labels()->attach(2);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/2/pivot", ['reviewer_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'related_id');
});

it('rejects a pivot BelongsTo outside the picker on pivot update', function () {
    $this->task->labels()->attach(1);

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/1/pivot", ['reviewer_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'reviewer_id');

    $this->putJson("/martis/api/resources/rw-tasks/{$this->task->id}/belongs-to-many/labels/1/pivot", ['reviewer_id' => 1])
        ->assertOk();
});

it('rejects a MorphToMany attach and pivot update outside the attach picker', function () {
    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/morph-to-many/topics/attach", ['related_id' => 2])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'related_id');

    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/morph-to-many/topics/attach", ['related_ids' => [1, 2]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'related_ids');

    expect(DB::table('rw_taggables')->count())->toBe(0);

    $this->postJson("/martis/api/resources/rw-tasks/{$this->task->id}/morph-to-many/topics/attach", ['related_id' => 1])
        ->assertCreated();
});

// ---------------------------------------------------------------------------
// Action fields
// ---------------------------------------------------------------------------

it('rejects an Action BelongsTo id outside the picker', function () {
    $this->postJson('/martis/api/resources/rw-tasks/actions/rw-assign', ['resources' => [$this->task->id], 'fields' => ['assignee_id' => 3]])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'assignee_id');

    $this->postJson('/martis/api/resources/rw-tasks/actions/rw-assign', ['resources' => [$this->task->id], 'fields' => ['assignee_id' => 1]])
        ->assertOk();
});
