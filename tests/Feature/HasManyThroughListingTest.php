<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough as EloquentHasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasManyThrough;
use Martis\Fields\ID;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A HasManyThrough panel lists its records through a join with the
 * intermediate table. Paginating the relation's bare query selected every
 * column of both tables, so the intermediate's `id`, timestamps and
 * `deleted_at` overwrote the record's: a row showed (and its View, Edit,
 * Delete, Restore and Force delete targeted) another project, and a trashed
 * project came back with `deleted_at: null`. The ids below differ between
 * the two tables, both soft-delete, and both carry a `name` column, so an
 * unqualified sort or search on it is ambiguous.
 */

class HMTLManagerModel extends Model
{
    protected $table = 'hmtl_managers';

    protected $fillable = ['name'];

    public function projects(): EloquentHasManyThrough
    {
        return $this->hasManyThrough(HMTLProjectModel::class, HMTLClientModel::class, 'manager_id', 'client_id');
    }
}

class HMTLClientModel extends Model
{
    use SoftDeletes;

    protected $table = 'hmtl_clients';

    protected $fillable = ['name', 'manager_id'];
}

class HMTLProjectModel extends Model
{
    use SoftDeletes;

    protected $table = 'hmtl_projects';

    protected $fillable = ['name', 'client_id'];
}

class HMTLProjectResource extends Resource
{
    public static function model(): string
    {
        return HMTLProjectModel::class;
    }

    public static function uriKey(): string
    {
        return 'hmtl-projects';
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('name')->sortable()->searchable()];
    }
}

class HMTLManagerResource extends Resource
{
    public static function model(): string
    {
        return HMTLManagerModel::class;
    }

    public static function uriKey(): string
    {
        return 'hmtl-managers';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name'), HasManyThrough::make('Projects', 'projects')->relatedResource('hmtl-projects')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['hmtl_projects', 'hmtl_clients', 'hmtl_managers'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('hmtl_managers', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('hmtl_clients', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('manager_id');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('hmtl_projects', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('client_id');
        $table->timestamps();
        $table->softDeletes();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(HMTLProjectResource::class);
    $registry->register(HMTLManagerResource::class);

    $this->manager = HMTLManagerModel::create(['name' => 'Manager']);
    $client = HMTLClientModel::forceCreate(['id' => 50, 'name' => 'Zulu client', 'manager_id' => $this->manager->id]);
    $this->active = HMTLProjectModel::forceCreate(['id' => 7, 'name' => 'Beta project', 'client_id' => $client->id]);
    $this->trashed = HMTLProjectModel::forceCreate(['id' => 8, 'name' => 'Alpha project', 'client_id' => $client->id]);
    $this->trashed->delete();
});

afterEach(function () {
    foreach (['hmtl_projects', 'hmtl_clients', 'hmtl_managers'] as $table) {
        Schema::dropIfExists($table);
    }
    app(ResourceRegistry::class)->flush();
});

function hmtlList(string $query = ''): array
{
    return test()->getJson('/martis/api/resources/hmtl-managers/'.test()->manager->id.'/has-many/projects'.$query)
        ->assertStatus(200)
        ->json('data');
}

it('lists each record with its own id', function () {
    expect(collect(hmtlList())->map(fn ($row) => [$row['id'], $row['name']])->all())->toBe([[7, 'Beta project']]);
});

it('lists a trashed record with its own id and deleted_at', function () {
    $rows = collect(hmtlList('?trashed=only'));

    expect($rows->pluck('id')->all())->toBe([8])
        ->and($rows->first()['deleted_at'])->not->toBeNull();
});

it('sorts and searches by a column the intermediate table also has', function () {
    expect(collect(hmtlList('?trashed=with&sort=name&direction=asc'))->pluck('name')->all())->toBe(['Alpha project', 'Beta project'])
        ->and(collect(hmtlList('?search=Beta'))->pluck('id')->all())->toBe([7]);
});
