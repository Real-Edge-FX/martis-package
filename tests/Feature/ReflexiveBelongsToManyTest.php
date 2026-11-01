<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// A reflexive BelongsToMany (a model related to itself). As in Nova, the
// attach picker lists every record of the related resource, the parent
// included: excluding it is the application's call, made in a relatable hook
// that reads the parent id from the route. Because writes follow the
// pickers, that hook also refuses attaching the record to itself.
// ---------------------------------------------------------------------------

class ReflexiveProjectModel extends Model
{
    protected $table = 'reflexive_projects';

    protected $fillable = ['title'];

    public function related(): EloquentBelongsToMany
    {
        return $this->belongsToMany(self::class, 'reflexive_project_links', 'project_id', 'related_id');
    }

    public function relatedExcludingSelf(): EloquentBelongsToMany
    {
        return $this->belongsToMany(self::class, 'reflexive_project_links', 'project_id', 'related_id');
    }
}

class ReflexiveProjectResource extends Resource
{
    public static function model(): string
    {
        return ReflexiveProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'reflexive-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsToMany::make('Related', 'related')
                ->relatedResource('reflexive-projects'),
            BelongsToMany::make('Related (excluding itself)', 'relatedExcludingSelf')
                ->relatedResource('reflexive-projects')
                ->relatableQueryUsing(fn (Request $request, $query) => $query->whereKeyNot($request->route('id'))),
        ];
    }
}

beforeEach(function () {
    Schema::dropIfExists('reflexive_project_links');
    Schema::dropIfExists('reflexive_projects');

    Schema::create('reflexive_projects', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    Schema::create('reflexive_project_links', function ($table) {
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('related_id');
        $table->primary(['project_id', 'related_id']);
    });

    app(ResourceRegistry::class)->register(ReflexiveProjectResource::class);
    $this->withoutMiddleware(MartisAuthenticate::class);
});

afterEach(function () {
    Schema::dropIfExists('reflexive_project_links');
    Schema::dropIfExists('reflexive_projects');
});

function reflexiveAttachableIds(int $projectId, string $relationship): array
{
    return collect(test()->getJson("/martis/api/resources/reflexive-projects/{$projectId}/belongs-to-many/{$relationship}/attachable")
        ->assertOk()
        ->json('data'))->pluck('id')->all();
}

it('lists the parent record among its own attachable records, as Nova does', function () {
    $apollo = ReflexiveProjectModel::create(['title' => 'Apollo']);
    $beta = ReflexiveProjectModel::create(['title' => 'Beta']);

    expect(reflexiveAttachableIds($apollo->id, 'related'))->toEqualCanonicalizing([$apollo->id, $beta->id]);
});

it('leaves the parent record out when a relatable hook excludes the route id', function () {
    $apollo = ReflexiveProjectModel::create(['title' => 'Apollo']);
    $beta = ReflexiveProjectModel::create(['title' => 'Beta']);

    expect(reflexiveAttachableIds($apollo->id, 'relatedExcludingSelf'))->toBe([$beta->id]);
});

it('refuses attaching the record to itself when the hook excludes it, and attaches another record', function () {
    $apollo = ReflexiveProjectModel::create(['title' => 'Apollo']);
    $beta = ReflexiveProjectModel::create(['title' => 'Beta']);
    $url = "/martis/api/resources/reflexive-projects/{$apollo->id}/belongs-to-many/relatedExcludingSelf/attach";

    $this->postJson($url, ['related_id' => $apollo->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'related_id');
    expect($apollo->relatedExcludingSelf()->count())->toBe(0);

    $this->postJson($url, ['related_id' => $beta->id])->assertStatus(201);
    expect($apollo->relatedExcludingSelf()->pluck('reflexive_projects.id')->all())->toBe([$beta->id]);
});
