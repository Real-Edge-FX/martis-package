<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A relationship panel sorts by the column as written, and qualifies the
 * columns it searches by only when its relation joins another table by
 * nature (hasManyThrough, belongsToMany, morphToMany: see
 * HasManyThroughListingTest). A plain hasMany keeps them as written: its
 * rows may carry an alias (withCount) or a column of a table the relation
 * itself joins, which the related table does not have.
 */

class RPSCOwner extends Model
{
    protected $table = 'rpsc_owners';

    protected $guarded = [];

    public function shops(): EloquentHasMany
    {
        return $this->hasMany(RPSCShop::class, 'owner_id');
    }

    public function regionShops(): EloquentHasMany
    {
        return $this->hasMany(RPSCShop::class, 'owner_id')
            ->join('rpsc_regions', 'rpsc_regions.id', '=', 'rpsc_shops.region_id')
            ->select('rpsc_shops.*', 'rpsc_regions.region');
    }
}

class RPSCShop extends Model
{
    protected $table = 'rpsc_shops';

    protected $guarded = [];

    // Eloquent's "always load this count" property: every query selects a
    // `sales_count` alias.
    protected $withCount = ['sales'];

    public function sales(): EloquentHasMany
    {
        return $this->hasMany(RPSCSale::class, 'shop_id');
    }
}

class RPSCSale extends Model
{
    protected $table = 'rpsc_sales';

    protected $guarded = [];
}

class RPSCShopResource extends Resource
{
    public static function model(): string
    {
        return RPSCShop::class;
    }

    public static function uriKey(): string
    {
        return 'rpsc-shops';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->sortable()->searchable(),
            Number::make('sales_count')->sortable(),
        ];
    }
}

class RPSCRegionShopResource extends Resource
{
    public static function model(): string
    {
        return RPSCShop::class;
    }

    public static function uriKey(): string
    {
        return 'rpsc-region-shops';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Text::make('region')->searchable(),
        ];
    }
}

class RPSCOwnerResource extends Resource
{
    public static function model(): string
    {
        return RPSCOwner::class;
    }

    public static function uriKey(): string
    {
        return 'rpsc-owners';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Shops', 'shops')->relatedResource('rpsc-shops'),
            HasMany::make('Region shops', 'regionShops')->relatedResource('rpsc-region-shops'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['rpsc_sales', 'rpsc_shops', 'rpsc_regions', 'rpsc_owners'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('rpsc_owners', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('rpsc_regions', function ($table) {
        $table->id();
        $table->string('region');
    });
    Schema::create('rpsc_shops', function ($table) {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('owner_id');
        $table->unsignedBigInteger('region_id')->nullable();
        $table->timestamps();
    });
    Schema::create('rpsc_sales', function ($table) {
        $table->id();
        $table->unsignedBigInteger('shop_id');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RPSCShopResource::class, RPSCRegionShopResource::class, RPSCOwnerResource::class] as $resource) {
        $registry->register($resource);
    }
});

afterEach(function () {
    foreach (['rpsc_sales', 'rpsc_shops', 'rpsc_regions', 'rpsc_owners'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('sorts a hasMany panel by a withCount alias of the related model', function () {
    $owner = RPSCOwner::create(['name' => 'Owner']);
    foreach (['Two sales' => 2, 'No sales' => 0, 'One sale' => 1] as $name => $sales) {
        $shop = RPSCShop::create(['name' => $name, 'owner_id' => $owner->id]);
        for ($i = 0; $i < $sales; $i++) {
            RPSCSale::create(['shop_id' => $shop->id]);
        }
    }

    $response = $this->getJson("/martis/api/resources/rpsc-owners/{$owner->id}/has-many/shops?sort=sales_count&direction=desc");

    $response->assertStatus(200);
    expect(array_column($response->json('data'), 'name'))->toBe(['Two sales', 'One sale', 'No sales']);
});

it('searches a hasMany panel by a column its relation joins', function () {
    $owner = RPSCOwner::create(['name' => 'Owner']);
    $north = DB::table('rpsc_regions')->insertGetId(['region' => 'North']);
    $south = DB::table('rpsc_regions')->insertGetId(['region' => 'South']);
    RPSCShop::create(['name' => 'Harbour', 'owner_id' => $owner->id, 'region_id' => $north]);
    RPSCShop::create(['name' => 'Market', 'owner_id' => $owner->id, 'region_id' => $south]);

    $response = $this->getJson("/martis/api/resources/rpsc-owners/{$owner->id}/has-many/regionShops?search=North");

    $response->assertStatus(200);
    expect(array_column($response->json('data'), 'name'))->toBe(['Harbour']);
});
