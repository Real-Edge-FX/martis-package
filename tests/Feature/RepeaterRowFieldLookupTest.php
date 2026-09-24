<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\Action;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Facades\Martis;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphTo;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Select;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Panel;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

// A field a Repeater's row declares (a Repeatable's fields()) is addressed
// by the Repeater's attribute and the row type: the per-field endpoints that
// serve a form's pickers (relatable options, remote Select options) read it
// from that row when the request sends `repeater` and `repeatable`. They only
// read the form's own fields, where no row field is declared (404, empty
// picker; 422 for a remote Select).

// ---------------------------------------------------------------------------
// Fixtures: Models
// ---------------------------------------------------------------------------

class RrlOrder extends Model
{
    protected $table = 'rrl_orders';

    protected $guarded = [];

    public $timestamps = false;
}

class RrlProduct extends Model
{
    protected $table = 'rrl_products';

    protected $guarded = [];

    public $timestamps = false;
}

class RrlTag extends Model
{
    protected $table = 'rrl_tags';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Fixtures: Repeatables
// ---------------------------------------------------------------------------

/** @return array<string, string> value => label */
function rrlUnits(string $term): array
{
    $matches = array_values(array_filter(['box', 'crate', 'pallet'], fn (string $unit) => $term === '' || str_contains($unit, $term)));

    return array_combine($matches, $matches);
}

/** A row type with every server-backed picker. */
class RrlProductLine extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('product', 'Product')->relatedResource('rrl-products')->titleAttribute('name')->nullable(),
            MorphTo::make('source', 'Source')->types([RrlProductResource::class, RrlTagResource::class])->nullable(),
            Tag::make('tags', 'Tags')->relatedResource('rrl-tags')->titleAttribute('name')->nullable(),
            BelongsTo::make('auditor', 'Auditor')->relatedResource('rrl-denied-products')->titleAttribute('name')->nullable(),
            Select::make('unit')->searchOptionsUsing(fn (string $term) => rrlUnits($term)),
            Text::make('note'),
        ];
    }
}

/** Another row type: the same attribute, another scope. */
class RrlServiceLine extends Repeatable
{
    public function fields(Request $request): array
    {
        return [
            BelongsTo::make('product', 'Service')
                ->relatedResource('rrl-products')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Beta%')),
        ];
    }
}

// ---------------------------------------------------------------------------
// Fixtures: Resources, Action and Tool
// ---------------------------------------------------------------------------

class RrlProductResource extends Resource
{
    public static function model(): string
    {
        return RrlProduct::class;
    }

    public static function uriKey(): string
    {
        return 'rrl-products';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable()];
    }

    // The target's fence: every picker that reaches products lists active ones.
    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

class RrlDeniedProductResource extends RrlProductResource
{
    public static function uriKey(): string
    {
        return 'rrl-denied-products';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class RrlTagResource extends Resource
{
    public static function model(): string
    {
        return RrlTag::class;
    }

    public static function uriKey(): string
    {
        return 'rrl-tags';
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

class RrlAddLinesAction extends Action
{
    public function fields(Request $request): array
    {
        return [Repeater::make('lines', 'Lines')->repeatables([RrlServiceLine::make()])];
    }
}

class RrlOrderResource extends Resource
{
    public static function model(): string
    {
        return RrlOrder::class;
    }

    public static function uriKey(): string
    {
        return 'rrl-orders';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            // The form's own product picker, with its own scope.
            BelongsTo::make('product', 'Featured product')
                ->relatedResource('rrl-products')
                ->titleAttribute('name')
                ->relatableQueryUsing(fn (Request $request, Builder $query) => $query->where('name', 'like', 'Active%'))
                ->nullable(),
            Panel::make('Lines', [
                Repeater::make('lines', 'Lines')->repeatables([RrlProductLine::make(), RrlServiceLine::make()]),
            ]),
        ];
    }

    public function actions(Request $request): array
    {
        return [RrlAddLinesAction::make()];
    }

    // The source's narrowing hook for tag pickers.
    public static function relatableRrlTags(Request $request, Builder $query): Builder
    {
        return $query->where('is_public', true);
    }
}

/** The Repeater on the update form only. */
class RrlUpdateFormOrderResource extends RrlOrderResource
{
    public static function uriKey(): string
    {
        return 'rrl-update-form-orders';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function fieldsForUpdate(Request $request): array
    {
        return [
            Text::make('name'),
            Repeater::make('lines', 'Lines')->repeatables([RrlProductLine::make()]),
        ];
    }
}

class RrlPrivateOrderResource extends RrlOrderResource
{
    public static function uriKey(): string
    {
        return 'rrl-private-orders';
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}

class RrlLinesTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Lines Tool', uriKey: 'rrl-lines-tool');
    }

    public function fields(Request $request): array
    {
        return [Repeater::make('lines', 'Lines')->repeatables([RrlProductLine::make()])];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('rrl_orders', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->foreignId('product_id')->nullable();
        $table->json('lines')->nullable();
    });

    Schema::create('rrl_products', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('rrl_tags', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('is_public')->default(true);
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([
        RrlOrderResource::class,
        RrlUpdateFormOrderResource::class,
        RrlPrivateOrderResource::class,
        RrlProductResource::class,
        RrlDeniedProductResource::class,
        RrlTagResource::class,
    ] as $resource) {
        $registry->register($resource);
    }

    Martis::tools([new RrlLinesTool]);

    RrlProduct::create(['name' => 'Active Product', 'is_active' => true]);
    RrlProduct::create(['name' => 'Inactive Product', 'is_active' => false]);
    RrlProduct::create(['name' => 'Beta Product', 'is_active' => true]);

    RrlTag::create(['name' => 'Public Tag', 'is_public' => true]);
    RrlTag::create(['name' => 'Private Tag', 'is_public' => false]);

    $this->order = RrlOrder::create(['name' => 'Order 1']);
});

afterEach(function () {
    Schema::dropIfExists('rrl_tags');
    Schema::dropIfExists('rrl_products');
    Schema::dropIfExists('rrl_orders');

    Martis::tools([]);
    app(ResourceRegistry::class)->flush();
});

const RRL_PRODUCT_LINE = '&repeater=lines&repeatable=rrl-product-line';

const RRL_SERVICE_LINE = '&repeater=lines&repeatable=rrl-service-line';

function rrlRelatable(string $resource, int|string $id, string $attribute, string $query = ''): string
{
    return "/martis/api/resources/{$resource}/{$id}/relatable/{$attribute}?per_page=30{$query}";
}

/** @return list<string> */
function rrlNames(TestResponse $response): array
{
    return collect($response->json('data'))->pluck('name')->all();
}

/** @return list<string> */
function rrlOptions(TestResponse $response): array
{
    return collect($response->json('data.options'))->pluck('value')->all();
}

// ---------------------------------------------------------------------------
// Relatable: the row type the request names
// ---------------------------------------------------------------------------

// The target's relatableQuery() (active products) and the resource's
// relatableRrlTags() (public tags) shape the lists, as on the form.
it('lists the options of a relation field a Repeater row declares', function (string $id, string $attribute, string $query, array $expected) {
    $response = $this->getJson(rrlRelatable('rrl-orders', $id === 'record' ? (string) $this->order->id : $id, $attribute, $query.RRL_PRODUCT_LINE));

    $response->assertOk();
    expect(rrlNames($response))->toBe($expected);
})->with([
    'create form' => '_',
    'update form' => 'record',
])->with([
    'BelongsTo' => ['product_id', '', ['Active Product', 'Beta Product']],
    'MorphTo' => ['source', '&related_resource=rrl-products', ['Active Product', 'Beta Product']],
    'Tag' => ['tags', '', ['Public Tag']],
]);

it('reads the declaration of the row type the request names', function () {
    $productLine = $this->getJson(rrlRelatable('rrl-orders', '_', 'product_id', RRL_PRODUCT_LINE));
    $serviceLine = $this->getJson(rrlRelatable('rrl-orders', '_', 'product_id', RRL_SERVICE_LINE));
    // Without a row, the form's own picker answers.
    $form = $this->getJson(rrlRelatable('rrl-orders', '_', 'product_id'));

    expect(rrlNames($productLine->assertOk()))->toBe(['Active Product', 'Beta Product'])
        ->and(rrlNames($serviceLine->assertOk()))->toBe(['Beta Product'])
        ->and(rrlNames($form->assertOk()))->toBe(['Active Product']);
});

it('searches the options of a Repeater row picker', function () {
    $response = $this->getJson(rrlRelatable('rrl-orders', '_', 'product_id', '&search=Beta'.RRL_PRODUCT_LINE));

    $response->assertOk();
    expect(rrlNames($response))->toBe(['Beta Product']);
});

it('finds a Repeater in the form the request names', function () {
    $response = $this->getJson(rrlRelatable('rrl-update-form-orders', $this->order->id, 'product_id', RRL_PRODUCT_LINE));

    $response->assertOk();
    expect(rrlNames($response))->toBe(['Active Product', 'Beta Product']);

    // The create forms (and fields()) declare no Repeater.
    $this->getJson(rrlRelatable('rrl-update-form-orders', '_', 'product_id', RRL_PRODUCT_LINE))->assertNotFound();
});

it('answers 404 for a row the form does not declare', function (string $query) {
    $this->getJson(rrlRelatable('rrl-orders', '_', 'product_id', $query))->assertNotFound();
})->with([
    'an unknown Repeater' => '&repeater=nope&repeatable=rrl-product-line',
    'a field that is not a Repeater' => '&repeater=name&repeatable=rrl-product-line',
    'an unknown row type' => '&repeater=lines&repeatable=nope',
    'a Repeater without its row type' => '&repeater=lines',
    'a row type without its Repeater' => '&repeatable=rrl-product-line',
]);

it('answers 404 for an attribute the row type does not declare as a relation field', function (string $attribute, string $query) {
    $this->getJson(rrlRelatable('rrl-orders', '_', $attribute, $query))->assertNotFound();
})->with([
    'a row Text field' => ['note', RRL_PRODUCT_LINE],
    'a field of the other row type only' => ['tags', RRL_SERVICE_LINE],
    'a field of the form only' => ['name', RRL_PRODUCT_LINE],
]);

it('keeps the viewAny gates of the resource and of the related resource', function () {
    $this->getJson(rrlRelatable('rrl-private-orders', '_', 'product_id', RRL_PRODUCT_LINE))->assertForbidden();
    $this->getJson(rrlRelatable('rrl-orders', '_', 'auditor_id', RRL_PRODUCT_LINE))->assertForbidden();
});

it('reads a Repeater row of an Action modal', function () {
    $url = '/martis/api/resources/rrl-orders/actions/rrl-add-lines-action/relatable/product_id?per_page=30';

    $response = $this->getJson($url.RRL_SERVICE_LINE);

    $response->assertOk();
    expect(rrlNames($response))->toBe(['Beta Product']);

    // The Action declares the picker in its Repeater only.
    $this->getJson($url)->assertNotFound();
});

// ---------------------------------------------------------------------------
// Remote Select options: the same row lookup
// ---------------------------------------------------------------------------

it('serves the options of a remote Select a Repeater row declares', function () {
    $url = '/martis/api/resources/rrl-orders/fields/unit/options?context=create&search=a';

    $response = $this->getJson($url.RRL_PRODUCT_LINE);

    $response->assertOk();
    expect(rrlOptions($response))->toBe(['crate', 'pallet']);

    $this->getJson($url)->assertStatus(422);
    $this->getJson($url.RRL_SERVICE_LINE)->assertStatus(422);

    $update = $this->getJson("/martis/api/resources/rrl-update-form-orders/fields/unit/options?context=update&id={$this->order->id}".RRL_PRODUCT_LINE);
    expect(rrlOptions($update->assertOk()))->toBe(['box', 'crate', 'pallet']);
});

it('serves the options of a remote Select in a Repeater row of a Tool form', function () {
    $url = '/martis/api/tools/rrl-lines-tool/fields/unit/options?search=box';

    $response = $this->getJson($url.RRL_PRODUCT_LINE);

    $response->assertOk();
    expect(rrlOptions($response))->toBe(['box']);

    $this->getJson($url)->assertStatus(422);
});
