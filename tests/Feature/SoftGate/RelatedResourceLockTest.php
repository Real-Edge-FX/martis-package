<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\HasOne;
use Martis\Fields\MorphMany;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A relationship panel lists the records of its related resource, so a related
 * resource the user is soft-locked from (`lockedFor()`, `requirePlan()`) closes
 * the panel's routes. They answer the lock like every other data endpoint of
 * the locked resource: `403` with `locked: true` and the `lock` payload, which
 * `martis:locked` reads. `viewAny` still wins: a user who may not list the
 * related resource gets the plain 403.
 */

final class RlpState
{
    public static bool $locked = true;
}

class RlpParent extends Model
{
    protected $table = 'rlp_parents';

    protected $fillable = ['name'];

    public function children(): EloquentHasMany
    {
        return $this->hasMany(RlpChild::class, 'parent_id');
    }

    public function child(): EloquentHasOne
    {
        return $this->hasOne(RlpChild::class, 'parent_id');
    }

    public function notes(): EloquentMorphMany
    {
        return $this->morphMany(RlpNote::class, 'notable');
    }

    public function note(): EloquentMorphOne
    {
        return $this->morphOne(RlpNote::class, 'notable');
    }

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(RlpTag::class, 'rlp_parent_tag', 'parent_id', 'tag_id');
    }

    public function labels(): EloquentMorphToMany
    {
        return $this->morphToMany(RlpLabel::class, 'labelable', 'rlp_labelables', 'labelable_id', 'label_id');
    }
}

class RlpChild extends Model
{
    protected $table = 'rlp_children';

    protected $fillable = ['title', 'parent_id'];
}

class RlpNote extends Model
{
    protected $table = 'rlp_notes';

    protected $fillable = ['title', 'notable_type', 'notable_id'];
}

class RlpTag extends Model
{
    protected $table = 'rlp_tags';

    protected $fillable = ['name'];
}

class RlpLabel extends Model
{
    protected $table = 'rlp_labels';

    protected $fillable = ['name'];
}

const RLP_MODAL = ['title' => 'Pro feature', 'message' => 'Upgrade to unlock.', 'cta' => ['label' => 'Upgrade', 'url' => '/billing']];

/** A related resource that locks itself while `RlpState::$locked`. */
abstract class RlpLockedResource extends Resource
{
    public function __construct(?Model $model = null)
    {
        parent::__construct($model);
        $this->lockedFor(fn (): bool => RlpState::$locked)->lockModal(RLP_MODAL);
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class RlpChildResource extends RlpLockedResource
{
    public static function model(): string
    {
        return RlpChild::class;
    }

    public static function uriKey(): string
    {
        return 'rlp-children';
    }
}

class RlpNoteResource extends RlpLockedResource
{
    public static function model(): string
    {
        return RlpNote::class;
    }

    public static function uriKey(): string
    {
        return 'rlp-notes';
    }
}

class RlpTagResource extends RlpLockedResource
{
    public static function model(): string
    {
        return RlpTag::class;
    }

    public static function uriKey(): string
    {
        return 'rlp-tags';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class RlpLabelResource extends RlpTagResource
{
    public static function model(): string
    {
        return RlpLabel::class;
    }

    public static function uriKey(): string
    {
        return 'rlp-labels';
    }
}

class RlpDenyViewAnyPolicy
{
    public function viewAny($user): bool
    {
        return false;
    }
}

/** An unlocked parent whose six panels point at the locked resources. */
class RlpParentResource extends Resource
{
    public static function model(): string
    {
        return RlpParent::class;
    }

    public static function uriKey(): string
    {
        return 'rlp-parents';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Children', 'children')->relatedResource('rlp-children'),
            HasOne::make('Child', 'child')->relatedResource('rlp-children'),
            MorphMany::make('Notes', 'notes')->relatedResource('rlp-notes'),
            MorphOne::make('Note', 'note')->relatedResource('rlp-notes'),
            BelongsToMany::make('Tags', 'tags')->relatedResource('rlp-tags'),
            MorphToMany::make('Labels', 'labels')->relatedResource('rlp-labels'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    RlpState::$locked = true;

    foreach (['rlp_labelables', 'rlp_labels', 'rlp_parent_tag', 'rlp_tags', 'rlp_notes', 'rlp_children', 'rlp_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('rlp_parents', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('rlp_children', fn ($t) => [$t->id(), $t->unsignedBigInteger('parent_id'), $t->string('title'), $t->timestamps()]);
    Schema::create('rlp_notes', fn ($t) => [$t->id(), $t->morphs('notable'), $t->string('title'), $t->timestamps()]);
    Schema::create('rlp_tags', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('rlp_parent_tag', fn ($t) => [$t->id(), $t->unsignedBigInteger('parent_id'), $t->unsignedBigInteger('tag_id'), $t->timestamps()]);
    Schema::create('rlp_labels', fn ($t) => [$t->id(), $t->string('name'), $t->timestamps()]);
    Schema::create('rlp_labelables', fn ($t) => [$t->id(), $t->unsignedBigInteger('label_id'), $t->morphs('labelable'), $t->timestamps()]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RlpChildResource::class, RlpNoteResource::class, RlpTagResource::class, RlpLabelResource::class, RlpParentResource::class] as $class) {
        $registry->register($class);
    }

    $this->parent = RlpParent::create(['name' => 'P']);
    $this->parent->children()->create(['title' => 'Child']);
    $this->parent->notes()->create(['title' => 'Note']);
    $this->tag = RlpTag::create(['name' => 'T']);
    $this->parent->tags()->attach($this->tag->id);
    $this->label = RlpLabel::create(['name' => 'L']);
    $this->parent->labels()->attach($this->label->id);
    $this->freeTag = RlpTag::create(['name' => 'Free']);
    $this->freeLabel = RlpLabel::create(['name' => 'FreeL']);
});

afterEach(function () {
    foreach (['rlp_labelables', 'rlp_labels', 'rlp_parent_tag', 'rlp_tags', 'rlp_notes', 'rlp_children', 'rlp_parents'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

/** Every route of the six panels, as [method, path, body]. */
function rlpRoutes(): array
{
    $tag = test()->tag->id;
    $freeTag = test()->freeTag->id;
    $freeLabel = test()->freeLabel->id;

    return [
        'has many list' => ['getJson', '/has-many/children', []],
        'has many create' => ['postJson', '/has-many/children', ['title' => 'x']],
        'has one show' => ['getJson', '/has-one/child', []],
        'has one create' => ['postJson', '/has-one/child', ['title' => 'x']],
        'morph many list' => ['getJson', '/morph-many/notes', []],
        'morph many create' => ['postJson', '/morph-many/notes', ['title' => 'x']],
        'morph one show' => ['getJson', '/morph-one/note', []],
        'belongs to many list' => ['getJson', '/belongs-to-many/tags', []],
        'belongs to many attachable' => ['getJson', '/belongs-to-many/tags/attachable', []],
        'belongs to many attach' => ['postJson', '/belongs-to-many/tags/attach', ['related_id' => $freeTag]],
        'belongs to many detach' => ['deleteJson', '/belongs-to-many/tags/'.$tag.'/detach', []],
        'belongs to many actions' => ['getJson', '/belongs-to-many/tags/actions', []],
        'morph to many list' => ['getJson', '/morph-to-many/labels', []],
        'morph to many attachable' => ['getJson', '/morph-to-many/labels/attachable', []],
        'morph to many attach' => ['postJson', '/morph-to-many/labels/attach', ['related_id' => $freeLabel]],
        'morph to many actions' => ['getJson', '/morph-to-many/labels/actions', []],
    ];
}

it('answers the lock payload on every relationship route whose related resource is locked', function () {
    $base = '/martis/api/resources/rlp-parents/'.$this->parent->id;

    foreach (rlpRoutes() as $label => [$method, $path, $body]) {
        $this->{$method}($base.$path, $body)
            ->assertForbidden()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('lock.reason', 'gated')
            ->assertJsonPath('lock.modal.title', 'Pro feature')
            ->assertJsonPath('lock.modal.cta.url', '/billing')
            ->assertJsonPath('message', __('martis::messages.feature_locked'));
    }

    // Nothing was written through any of them.
    expect(RlpChild::query()->count())->toBe(1)
        ->and(RlpNote::query()->count())->toBe(1)
        ->and($this->parent->tags()->count())->toBe(1)
        ->and($this->parent->labels()->count())->toBe(1);
});

it('opens the panels again once the lock lifts', function () {
    RlpState::$locked = false;
    $base = '/martis/api/resources/rlp-parents/'.$this->parent->id;

    $this->getJson($base.'/has-many/children')->assertOk();
    $this->getJson($base.'/belongs-to-many/tags')->assertOk();
    $this->getJson($base.'/morph-to-many/labels')->assertOk();
});

it('keeps the plain 403, with no lock, for a user who may not list the locked related resource', function () {
    // viewAny wins over the lock: the user is not told what a plan would unlock.
    Gate::policy(RlpChild::class, RlpDenyViewAnyPolicy::class);
    Resource::flushPolicyCache();

    $response = $this->getJson('/martis/api/resources/rlp-parents/'.$this->parent->id.'/has-many/children')->assertForbidden();

    expect($response->json('locked'))->toBeNull()
        ->and($response->json('lock'))->toBeNull();
});
