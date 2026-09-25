<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough as EloquentHasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasOneThrough;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A HasOneThrough card deletes the record it shows (`?relatedId=`) also when
 * soft deletes move the relationship to another record: the shown record
 * trashed, or the intermediate record it hung from trashed, leaves the
 * relationship on another record, and the delete answers 409 and touches
 * nothing. Aimed at the record the relationship holds, it deletes that one
 * only.
 */

class ORTParentModel extends Model
{
    protected $table = 'ort_parents';

    protected $fillable = ['name'];

    public function project(): EloquentHasOneThrough
    {
        return $this->hasOneThrough(ORTProjectModel::class, ORTTeamModel::class, 'parent_id', 'team_id');
    }
}

class ORTTeamModel extends Model
{
    use SoftDeletes;

    protected $table = 'ort_teams';

    protected $fillable = ['parent_id'];
}

class ORTProjectModel extends Model
{
    use SoftDeletes;

    protected $table = 'ort_projects';

    protected $fillable = ['title', 'team_id'];
}

class ORTProjectResource extends Resource
{
    public static function model(): string
    {
        return ORTProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'ort-projects';
    }

    public function fields(Request $request): array
    {
        return [Text::make('title')];
    }
}

class ORTParentResource extends Resource
{
    public static function model(): string
    {
        return ORTParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'ort-parents';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name'), HasOneThrough::make('Project', 'project')->relatedResource('ort-projects')];
    }
}

const ORT_TABLES = ['ort_projects', 'ort_teams', 'ort_parents'];

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (ORT_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('ort_parents', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('ort_teams', function ($table) {
        $table->id();
        $table->unsignedBigInteger('parent_id');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('ort_projects', function ($table) {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('title');
        $table->timestamps();
        $table->softDeletes();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ORTProjectResource::class);
    $registry->register(ORTParentResource::class);

    $this->parent = ORTParentModel::create(['name' => 'Parent']);
    $this->url = '/martis/api/resources/ort-parents/'.$this->parent->id.'/has-one/project';
});

afterEach(function () {
    foreach (ORT_TABLES as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

it('refuses a delete aimed at a shown record that was trashed, once another took its place', function () {
    $team = ORTTeamModel::create(['parent_id' => $this->parent->id]);
    $shown = ORTProjectModel::create(['title' => 'Shown', 'team_id' => $team->id]);
    $other = ORTProjectModel::create(['title' => 'Other', 'team_id' => $team->id]);
    expect($this->getJson($this->url)->json('data.id'))->toBe($shown->id);

    $shown->delete();
    expect($this->getJson($this->url)->json('data.id'))->toBe($other->id);

    $this->deleteJson($this->url.'?relatedId='.$shown->id)->assertStatus(409);

    expect(ORTProjectModel::find($other->id))->not->toBeNull()
        ->and(ORTProjectModel::withTrashed()->find($shown->id)?->trashed())->toBeTrue();
});

it('refuses a delete aimed at a shown record whose intermediate record was trashed', function () {
    $shownTeam = ORTTeamModel::create(['parent_id' => $this->parent->id]);
    $shown = ORTProjectModel::create(['title' => 'Shown', 'team_id' => $shownTeam->id]);
    $otherTeam = ORTTeamModel::create(['parent_id' => $this->parent->id]);
    $other = ORTProjectModel::create(['title' => 'Other', 'team_id' => $otherTeam->id]);
    expect($this->getJson($this->url)->json('data.id'))->toBe($shown->id);

    $shownTeam->delete();
    expect($this->getJson($this->url)->json('data.id'))->toBe($other->id);

    $this->deleteJson($this->url.'?relatedId='.$shown->id)->assertStatus(409);

    expect(ORTProjectModel::find($shown->id))->not->toBeNull()
        ->and(ORTProjectModel::find($other->id))->not->toBeNull();
});

it('deletes only the record the card names when it is the one the relationship holds', function () {
    $team = ORTTeamModel::create(['parent_id' => $this->parent->id]);
    $shown = ORTProjectModel::create(['title' => 'Shown', 'team_id' => $team->id]);
    $other = ORTProjectModel::create(['title' => 'Other', 'team_id' => $team->id]);

    $this->deleteJson($this->url.'?relatedId='.$shown->id)->assertOk();

    expect(ORTProjectModel::withTrashed()->find($shown->id)?->trashed())->toBeTrue()
        ->and(ORTProjectModel::find($other->id))->not->toBeNull()
        ->and(ORTTeamModel::find($team->id))->not->toBeNull();
});
