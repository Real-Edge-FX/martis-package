<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphTo;
use Martis\Fields\MorphToMany;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// The relation pickers of a pivot field (a BelongsTo, MorphTo or Tag in the
// ->fields() of a BelongsToMany / MorphToMany) load their options from the
// relationship panel: {panel}/pivot-fields/relatable/{field} in the attach
// modal, {panel}/pivot-fields/{relatedId}/relatable/{field} in the pivot edit
// modal. They used to ask the parent resource's relatable endpoint, which
// reads the parent's forms, where no pivot field is declared (404, empty
// picker).

// ---------------------------------------------------------------------------
// Fixtures: Models
// ---------------------------------------------------------------------------

class PfrProject extends Model
{
    protected $table = 'pfr_projects';

    protected $guarded = [];

    public $timestamps = false;

    public function members(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PfrMember::class, 'pfr_project_member', 'project_id', 'member_id');
    }

    public function watchers(): EloquentBelongsToMany
    {
        return $this->belongsToMany(PfrMember::class, 'pfr_project_watcher', 'project_id', 'member_id');
    }

    public function tags(): EloquentMorphToMany
    {
        return $this->morphToMany(PfrTag::class, 'taggable', 'pfr_taggables', 'taggable_id', 'tag_id');
    }
}

class PfrMember extends Model
{
    protected $table = 'pfr_members';

    protected $guarded = [];

    public $timestamps = false;
}

class PfrTag extends Model
{
    protected $table = 'pfr_tags';

    protected $guarded = [];

    public $timestamps = false;
}

class PfrRole extends Model
{
    protected $table = 'pfr_roles';

    protected $guarded = [];

    public $timestamps = false;
}

class PfrLabel extends Model
{
    protected $table = 'pfr_labels';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Fixtures: Resources and policy
// ---------------------------------------------------------------------------

/**
 * The pivot fields both panels declare.
 *
 * @return list<FieldContract>
 */
function pfrPivotFields(): array
{
    return [
        BelongsTo::make('role', 'Role')->relatedResource('pfr-roles')->titleAttribute('name')->nullable(),
        MorphTo::make('reviewer', 'Reviewer')->types([PfrRoleResource::class, PfrLabelResource::class])->nullable(),
        Tag::make('labels', 'Labels')->relatedResource('pfr-labels')->titleAttribute('name')->nullable(),
        BelongsTo::make('backup', 'Backup')
            ->relatedResource('pfr-roles')
            ->titleAttribute('name')
            ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%'))
            ->nullable(),
        BelongsTo::make('auditor', 'Auditor')->relatedResource('pfr-denied-roles')->titleAttribute('name')->nullable(),
        Text::make('note'),
        Repeater::make('shifts', 'Shifts')->repeatables([PfrShiftRow::make()]),
    ];
}

/** A Repeater row among the pivot fields: its role picker has its own scope. */
class PfrShiftRow extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('role', 'Role')
                ->relatedResource('pfr-roles')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%')),
        ];
    }
}

class PfrRoleResource extends Resource
{
    public static function model(): string
    {
        return PfrRole::class;
    }

    public static function uriKey(): string
    {
        return 'pfr-roles';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    // The target's fence: every picker that reaches roles lists active ones.
    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

class PfrDeniedRoleResource extends PfrRoleResource
{
    public static function uriKey(): string
    {
        return 'pfr-denied-roles';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class PfrLabelResource extends Resource
{
    public static function model(): string
    {
        return PfrLabel::class;
    }

    public static function uriKey(): string
    {
        return 'pfr-labels';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }
}

class PfrMemberResource extends Resource
{
    public static function model(): string
    {
        return PfrMember::class;
    }

    public static function uriKey(): string
    {
        return 'pfr-members';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class PfrTagResource extends Resource
{
    public static function model(): string
    {
        return PfrTag::class;
    }

    public static function uriKey(): string
    {
        return 'pfr-tags';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class PfrProjectResource extends Resource
{
    public static function model(): string
    {
        return PfrProject::class;
    }

    public static function uriKey(): string
    {
        return 'pfr-projects';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            // A picker of the resource's own forms, not a pivot field.
            BelongsTo::make('lead', 'Lead')->relatedResource('pfr-roles')->titleAttribute('name')->nullable(),
            BelongsToMany::make('Members', 'members')
                ->relatedResource('pfr-members')
                ->fields(fn () => pfrPivotFields()),
            BelongsToMany::make('Watchers', 'watchers')->relatedResource('pfr-members'),
            Section::make(null, [
                MorphToMany::make('Tags', 'tags')
                    ->relatedResource('pfr-tags')
                    ->fields(fn () => pfrPivotFields()),
            ]),
        ];
    }

    // The source's narrowing hook for label pickers.
    public static function relatablePfrLabels(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

class PfrProjectPolicy
{
    /** @var array<string, bool> */
    public static array $allow = [];

    public function viewAny(?User $user): bool
    {
        return self::$allow['viewAny'] ?? true;
    }

    public function view(?User $user, Model $project): bool
    {
        return self::$allow['view'] ?? true;
    }

    public function update(?User $user, Model $project): bool
    {
        return self::$allow['update'] ?? true;
    }

    public function attachAnyPfrMember(User $user, Model $project): bool
    {
        return self::$allow['attachAny'] ?? true;
    }

    public function attachAnyPfrTag(User $user, Model $project): bool
    {
        return self::$allow['attachAny'] ?? true;
    }

    // Members: the pivot row of one member is locked. Tags declare no
    // updatePivotPfrTag(), so update() decides for them.
    public function updatePivotPfrMember(User $user, Model $project, Model $member): bool
    {
        return $member->getAttribute('name') !== 'Locked';
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('pfr_projects', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    foreach (['pfr_members', 'pfr_tags'] as $name) {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    Schema::create('pfr_roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('pfr_labels', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_public')->default(true);
    });

    Schema::create('pfr_project_member', function (Blueprint $table) {
        $table->foreignId('project_id');
        $table->foreignId('member_id');
    });

    Schema::create('pfr_taggables', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tag_id');
        $table->morphs('taggable');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        PfrProjectResource::class,
        PfrMemberResource::class,
        PfrTagResource::class,
        PfrRoleResource::class,
        PfrDeniedRoleResource::class,
        PfrLabelResource::class,
    ] as $resource) {
        $registry->register($resource);
    }

    PfrRole::create(['name' => 'Active Role', 'is_active' => true]);
    PfrRole::create(['name' => 'Inactive Role', 'is_active' => false]);
    PfrRole::create(['name' => 'Beta Role', 'is_active' => true]);

    PfrLabel::create(['name' => 'Public Label', 'is_public' => true]);
    PfrLabel::create(['name' => 'Private Label', 'is_public' => false]);

    $this->project = PfrProject::create(['name' => 'Apollo']);
    $this->member = PfrMember::create(['name' => 'Ann']);
    $this->lockedMember = PfrMember::create(['name' => 'Locked']);
    $this->tag = PfrTag::create(['name' => 'Urgent']);

    $this->project->members()->attach([$this->member->id, $this->lockedMember->id]);
    $this->project->tags()->attach($this->tag->id);
});

afterEach(function () {
    PfrProjectPolicy::$allow = [];

    foreach (['pfr_taggables', 'pfr_project_member', 'pfr_labels', 'pfr_roles', 'pfr_tags', 'pfr_members', 'pfr_projects'] as $table) {
        Schema::dropIfExists($table);
    }

    app(ResourceRegistry::class)->flush();
});

/** The attach modal's pickers. */
function pfrAttachRelatable(int|string $id, string $panel, string $attribute, string $query = ''): string
{
    return "/martis/api/resources/pfr-projects/{$id}/{$panel}/pivot-fields/relatable/{$attribute}?per_page=30{$query}";
}

/** The pivot edit modal's pickers, for the pivot row of one attached record. */
function pfrPivotRelatable(int|string $id, string $panel, int|string $relatedId, string $attribute, string $query = ''): string
{
    return "/martis/api/resources/pfr-projects/{$id}/{$panel}/pivot-fields/{$relatedId}/relatable/{$attribute}?per_page=30{$query}";
}

/** @return list<string> */
function pfrNames(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('name')->all();
}

/** The record the panel lists whose pivot row the edit modal opens. */
function pfrAttachedId(string $panel): int
{
    return str_starts_with($panel, 'belongs-to-many')
        ? (int) PfrMember::query()->where('name', 'Ann')->value('id')
        : (int) PfrTag::query()->where('name', 'Urgent')->value('id');
}

dataset('pfr panels', [
    'BelongsToMany panel' => 'belongs-to-many/members',
    'MorphToMany panel in a layout' => 'morph-to-many/tags',
]);

// The target's relatableQuery() (active roles), the parent resource's
// relatablePfrLabels() (public labels) and the pivot field's own
// relatableQueryUsing() (Beta roles) shape the lists, as on the forms.
dataset('pfr pivot pickers', [
    'BelongsTo' => ['role_id', '', ['Active Role', 'Beta Role']],
    'MorphTo' => ['reviewer', '&related_resource=pfr-roles', ['Active Role', 'Beta Role']],
    'Tag' => ['labels', '', ['Public Label']],
    'BelongsTo with relatableQueryUsing()' => ['backup_id', '', ['Beta Role']],
]);

// ---------------------------------------------------------------------------
// The panel's pivot fields
// ---------------------------------------------------------------------------

it('lists the options of a pivot relation field in the attach modal', function (string $panel, string $attribute, string $query, array $expected) {
    $response = $this->getJson(pfrAttachRelatable($this->project->id, $panel, $attribute, $query));

    $response->assertOk();
    expect(pfrNames($response))->toBe($expected);
})->with('pfr panels')->with('pfr pivot pickers');

it('lists the options of a pivot relation field in the pivot edit modal', function (string $panel, string $attribute, string $query, array $expected) {
    $response = $this->getJson(pfrPivotRelatable($this->project->id, $panel, pfrAttachedId($panel), $attribute, $query));

    $response->assertOk();
    expect(pfrNames($response))->toBe($expected);
})->with('pfr panels')->with('pfr pivot pickers');

it('searches the options of a pivot relation field', function (string $panel) {
    $attach = $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'role_id', '&search=Beta'));
    $edit = $this->getJson(pfrPivotRelatable($this->project->id, $panel, pfrAttachedId($panel), 'role_id', '&search=Beta'));

    expect(pfrNames($attach->assertOk()))->toBe(['Beta Role'])
        ->and(pfrNames($edit->assertOk()))->toBe(['Beta Role']);
})->with('pfr panels');

it('reads a picker in a Repeater row of the pivot fields from that row', function (string $panel) {
    $row = '&repeater=shifts&repeatable=pfr-shift-row';

    $attach = $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'role_id', $row));
    $edit = $this->getJson(pfrPivotRelatable($this->project->id, $panel, pfrAttachedId($panel), 'role_id', $row));

    expect(pfrNames($attach->assertOk()))->toBe(['Beta Role'])
        ->and(pfrNames($edit->assertOk()))->toBe(['Beta Role']);

    $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'labels', $row))->assertNotFound();
})->with('pfr panels');

// ---------------------------------------------------------------------------
// What the panel does not declare
// ---------------------------------------------------------------------------

it('answers 404 for an attribute the panel does not declare as a pivot relation field', function (string $panel, string $attribute) {
    $this->getJson(pfrAttachRelatable($this->project->id, $panel, $attribute))->assertNotFound();
})->with([
    'a pivot Text field' => ['belongs-to-many/members', 'note'],
    'a picker of the resource forms' => ['belongs-to-many/members', 'lead_id'],
    'an undeclared attribute' => ['morph-to-many/tags', 'nope'],
    'a panel without pivot fields' => ['belongs-to-many/watchers', 'role_id'],
]);

it('answers 404 for a relationship the resource does not declare with the route type', function (string $panel) {
    $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'role_id'))->assertNotFound();
    $this->getJson(pfrPivotRelatable($this->project->id, $panel, $this->member->id, 'role_id'))->assertNotFound();
})->with(['morph-to-many/members', 'belongs-to-many/tags', 'belongs-to-many/nope']);

it('answers 404 for a parent record or a related record that does not exist', function (string $panel) {
    $this->getJson(pfrAttachRelatable(999999, $panel, 'role_id'))->assertNotFound();
    $this->getJson(pfrPivotRelatable(999999, $panel, pfrAttachedId($panel), 'role_id'))->assertNotFound();
    $this->getJson(pfrPivotRelatable($this->project->id, $panel, 999999, 'role_id'))->assertNotFound();
    $this->getJson(pfrPivotRelatable($this->project->id, $panel, 'not-a-key', 'role_id'))->assertNotFound();
})->with('pfr panels');

it('answers 404 in the pivot edit modal for a record the relationship does not attach', function (string $panel) {
    // It exists, but has no pivot row on this parent to edit.
    $outsider = str_starts_with($panel, 'belongs-to-many')
        ? PfrMember::create(['name' => 'Outsider'])
        : PfrTag::create(['name' => 'Outsider']);

    $this->getJson(pfrPivotRelatable($this->project->id, $panel, $outsider->id, 'role_id'))->assertNotFound();
})->with('pfr panels');

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

it('keeps the viewAny and view gates of the parent resource', function (string $panel, string $ability) {
    Gate::policy(PfrProject::class, PfrProjectPolicy::class);
    $this->actingAs((new User)->forceFill(['id' => 7]));
    PfrProjectPolicy::$allow = [$ability => false];

    $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'role_id'))->assertForbidden();
    $this->getJson(pfrPivotRelatable($this->project->id, $panel, pfrAttachedId($panel), 'role_id'))->assertForbidden();
})->with('pfr panels')->with(['viewAny', 'view']);

it('asks for the attach permission in the attach modal', function (string $panel) {
    Gate::policy(PfrProject::class, PfrProjectPolicy::class);
    $this->actingAs((new User)->forceFill(['id' => 7]));

    $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'role_id'))->assertOk();

    PfrProjectPolicy::$allow = ['attachAny' => false];

    $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'role_id'))->assertForbidden();
    // The pivot edit modal asks for the pivot update instead.
    $this->getJson(pfrPivotRelatable($this->project->id, $panel, pfrAttachedId($panel), 'role_id'))->assertOk();
})->with('pfr panels');

it('asks for the pivot update permission of the attached record in the pivot edit modal', function () {
    Gate::policy(PfrProject::class, PfrProjectPolicy::class);
    $this->actingAs((new User)->forceFill(['id' => 7]));

    // Members: updatePivotPfrMember() decides per attached member.
    $this->getJson(pfrPivotRelatable($this->project->id, 'belongs-to-many/members', $this->member->id, 'role_id'))->assertOk();
    $this->getJson(pfrPivotRelatable($this->project->id, 'belongs-to-many/members', $this->lockedMember->id, 'role_id'))->assertForbidden();

    // Tags: no updatePivotPfrTag(), so the parent's update() decides.
    $this->getJson(pfrPivotRelatable($this->project->id, 'morph-to-many/tags', $this->tag->id, 'role_id'))->assertOk();

    PfrProjectPolicy::$allow = ['update' => false];

    $this->getJson(pfrPivotRelatable($this->project->id, 'morph-to-many/tags', $this->tag->id, 'role_id'))->assertForbidden();
    // The attach modal asks for the attach permission instead.
    $this->getJson(pfrAttachRelatable($this->project->id, 'morph-to-many/tags', 'role_id'))->assertOk();
});

it('keeps the viewAny gate of the related resource', function (string $panel) {
    $this->getJson(pfrAttachRelatable($this->project->id, $panel, 'auditor_id'))->assertForbidden();
    $this->getJson(pfrPivotRelatable($this->project->id, $panel, pfrAttachedId($panel), 'auditor_id'))->assertForbidden();
})->with('pfr panels');
