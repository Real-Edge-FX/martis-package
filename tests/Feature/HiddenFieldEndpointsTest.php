<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Facades\Martis;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\Field;
use Martis\Fields\Repeatable;
use Martis\Fields\Repeater;
use Martis\Fields\Select;
use Martis\Fields\Slug;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

// ===========================================================================
// The per-field form endpoints and a field the user cannot see (v1.38.0).
//
// The relatable options (of a form, an Action, a pivot action, a pivot
// field), the remote Select search (of a Resource or a Tool), the Slug check
// and the dependsOn sync found their field without asking whether the user
// may see it, in a Repeater row too: a hidden field listed the options its
// scope derives and told that it exists. A field the user cannot see
// (canSee(), or canSeeForModel() for the record the form edits, or the new
// one a create form fills), a Repeater row field hidden that way and every
// row field of a hidden Repeater now answer exactly like an undeclared one.
// ===========================================================================

class HFEOrder extends Model
{
    protected $table = 'hfe_orders';

    protected $guarded = [];

    public $timestamps = false;

    public function tags(): EloquentBelongsToMany
    {
        return $this->belongsToMany(HFECustomer::class, 'hfe_order_tag', 'order_id', 'tag_id');
    }
}

class HFECustomer extends Model
{
    protected $table = 'hfe_customers';

    protected $guarded = [];

    public $timestamps = false;
}

/** @return list<string> */
function hfeOptions(string $term): array
{
    return ['box', 'crate'];
}

/**
 * A hidden and a visible picker, and a hidden and a visible remote Select.
 *
 * @return list<Field>
 */
function hfePickers(): array
{
    return [
        BelongsTo::make('customer', 'Customer')->relatedResource('hfe-customers')->nullable()->canSee(fn () => false),
        BelongsTo::make('agent', 'Agent')->relatedResource('hfe-customers')->nullable(),
        Select::make('unit')->searchOptionsUsing(fn (string $term) => hfeOptions($term))->canSee(fn () => false),
        Select::make('size')->searchOptionsUsing(fn (string $term) => hfeOptions($term)),
    ];
}

class HFELine extends Repeatable
{
    public function fields(Request $request): array
    {
        return hfePickers();
    }
}

class HFEAction extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Done.');
    }

    public function fields(Request $request): array
    {
        return [...hfePickers(), Repeater::make('lines', 'Lines')->repeatables([HFELine::make()])];
    }
}

class HFETool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Hidden Fields Tool', uriKey: 'hfe-tool');
    }

    public function fields(Request $request): array
    {
        return [...hfePickers(), Repeater::make('lines', 'Lines')->repeatables([HFELine::make()])];
    }
}

class HFEOrderResource extends Resource
{
    public static function model(): string
    {
        return HFEOrder::class;
    }

    public static function uriKey(): string
    {
        return 'hfe-orders';
    }

    public function fields(Request $request): array
    {
        $react = fn (array $form, Request $request, Text $field) => $field->placeholder('Reacted');

        return [
            Text::make('name')->nullable(),
            ...hfePickers(),
            // Seen on an open order only.
            BelongsTo::make('reviewer', 'Reviewer')->relatedResource('hfe-customers')->nullable()
                ->canSeeForModel(fn (Request $request, Model $model): bool => (bool) $model->getAttribute('open')),
            Slug::make('slug')->canSee(fn () => false),
            Slug::make('code'),
            Text::make('tier')->dependsOn(['name'], $react)->canSee(fn () => false),
            Text::make('level')->dependsOn(['name'], $react),
            Repeater::make('lines', 'Lines')->repeatables([HFELine::make()]),
            Repeater::make('secret_lines', 'Secret lines')->repeatables([HFELine::make()])->canSee(fn () => false),
            BelongsToMany::make('Tags', 'tags')
                ->relatedResource('hfe-customers')
                ->fields(fn () => [
                    BelongsTo::make('approver', 'Approver')->relatedResource('hfe-customers')->nullable()->canSee(fn () => false),
                    BelongsTo::make('checker', 'Checker')->relatedResource('hfe-customers')->nullable(),
                ])
                ->actions(fn () => [HFEAction::make()]),
        ];
    }

    public function actions(Request $request): array
    {
        return [HFEAction::make()];
    }
}

class HFECustomerResource extends Resource
{
    public static function model(): string
    {
        return HFECustomer::class;
    }

    public static function uriKey(): string
    {
        return 'hfe-customers';
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

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['hfe_order_tag', 'hfe_customers', 'hfe_orders'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('hfe_orders', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('slug')->nullable();
        $table->string('code')->nullable();
        $table->boolean('open')->default(false);
    });
    Schema::create('hfe_customers', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('hfe_order_tag', function ($table) {
        $table->unsignedBigInteger('order_id');
        $table->unsignedBigInteger('tag_id');
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(HFEOrderResource::class);
    $registry->register(HFECustomerResource::class);

    Martis::tools([new HFETool]);

    HFECustomer::create(['name' => 'Acme']);
    $this->order = HFEOrder::create(['name' => 'Order', 'open' => false]);
    $this->openOrder = HFEOrder::create(['name' => 'Open order', 'open' => true]);
    $this->order->tags()->attach(HFECustomer::sole()->id);
});

afterEach(function () {
    foreach (['hfe_order_tag', 'hfe_customers', 'hfe_orders'] as $table) {
        Schema::dropIfExists($table);
    }

    Martis::tools([]);
});

/**
 * Assert that the answer for a field matches the one for an undeclared
 * attribute (`nope`), but for the attribute each one names.
 */
function hfeLikeUndeclared(TestResponse $response, TestResponse $undeclared, string $attribute): void
{
    expect($response->status())->toBe($undeclared->status())
        ->and($response->json())->toEqual(json_decode(str_replace('nope', $attribute, (string) $undeclared->getContent()), true));
}

const HFE_ROW = '&repeater=lines&repeatable=h-f-e-line';

// ---------------------------------------------------------------------------
// Relatable options
// ---------------------------------------------------------------------------

it('answers the picker of a form field the user cannot see like an undeclared one', function (string $id) {
    $id = $id === 'record' ? (string) $this->order->id : $id;
    $uri = fn (string $attribute, string $query = ''): string => "/martis/api/resources/hfe-orders/{$id}/relatable/{$attribute}?per_page=10{$query}";

    $undeclared = $this->getJson($uri('nope'))->assertNotFound();

    hfeLikeUndeclared($this->getJson($uri('customer_id')), $undeclared, 'customer_id');
    hfeLikeUndeclared($this->getJson($uri('customer_id', HFE_ROW)), $this->getJson($uri('nope', HFE_ROW)), 'customer_id');
    // Every row field of a Repeater the user cannot see.
    hfeLikeUndeclared($this->getJson($uri('agent_id', '&repeater=secret_lines&repeatable=h-f-e-line')), $undeclared, 'agent_id');

    expect($this->getJson($uri('agent_id'))->assertOk()->json('data.*.name'))->toBe(['Acme'])
        ->and($this->getJson($uri('agent_id', HFE_ROW))->assertOk()->json('data.*.name'))->toBe(['Acme']);
})->with(['create form' => '_', 'update form' => 'record']);

it('answers the picker of a field hidden for the record its form edits like an undeclared one', function () {
    $uri = fn (int|string $id): string => "/martis/api/resources/hfe-orders/{$id}/relatable/reviewer_id";
    $undeclared = $this->getJson("/martis/api/resources/hfe-orders/{$this->order->id}/relatable/nope");

    hfeLikeUndeclared($this->getJson($uri($this->order->id)), $undeclared, 'reviewer_id');
    // A create form fills a new order, which is not open.
    hfeLikeUndeclared($this->getJson($uri('_')), $undeclared, 'reviewer_id');

    expect($this->getJson($uri($this->openOrder->id))->assertOk()->json('data.*.name'))->toBe(['Acme']);
});

it('answers the picker of an Action field the user cannot see like an undeclared one', function (string $base) {
    $base = str_replace('{order}', (string) $this->order->id, $base);
    $undeclared = $this->getJson("{$base}/relatable/nope")->assertNotFound();

    hfeLikeUndeclared($this->getJson("{$base}/relatable/customer_id"), $undeclared, 'customer_id');
    hfeLikeUndeclared($this->getJson("{$base}/relatable/customer_id?".ltrim(HFE_ROW, '&')), $undeclared, 'customer_id');

    expect($this->getJson("{$base}/relatable/agent_id")->assertOk()->json('data.*.name'))->toBe(['Acme'])
        ->and($this->getJson("{$base}/relatable/agent_id?".ltrim(HFE_ROW, '&'))->assertOk()->json('data.*.name'))->toBe(['Acme']);
})->with([
    'resource action' => '/martis/api/resources/hfe-orders/actions/h-f-e-action',
    'pivot action' => '/martis/api/resources/hfe-orders/{order}/belongs-to-many/tags/actions/h-f-e-action',
]);

it('answers the picker of a pivot field the user cannot see like an undeclared one', function (string $modal) {
    $tag = HFECustomer::sole();
    $base = "/martis/api/resources/hfe-orders/{$this->order->id}/belongs-to-many/tags/pivot-fields".($modal === 'edit' ? "/{$tag->id}" : '');
    $undeclared = $this->getJson("{$base}/relatable/nope")->assertNotFound();

    hfeLikeUndeclared($this->getJson("{$base}/relatable/approver_id"), $undeclared, 'approver_id');

    expect($this->getJson("{$base}/relatable/checker_id")->assertOk()->json('data.*.name'))->toBe(['Acme']);
})->with(['attach', 'edit']);

// ---------------------------------------------------------------------------
// Remote Select search
// ---------------------------------------------------------------------------

it('answers the option search of a Select the user cannot see like an undeclared one', function (string $query) {
    $query = str_replace('{order}', (string) $this->order->id, $query);
    $uri = fn (string $attribute, string $row = ''): string => "/martis/api/resources/hfe-orders/fields/{$attribute}/options?search=b{$query}{$row}";

    $undeclared = $this->getJson($uri('nope'))->assertStatus(422);

    hfeLikeUndeclared($this->getJson($uri('unit')), $undeclared, 'unit');
    hfeLikeUndeclared($this->getJson($uri('unit', HFE_ROW)), $undeclared, 'unit');
    hfeLikeUndeclared($this->getJson($uri('size', '&repeater=secret_lines&repeatable=h-f-e-line')), $undeclared, 'size');

    expect($this->getJson($uri('size'))->assertOk()->json('data.options'))->not->toBeEmpty()
        ->and($this->getJson($uri('size', HFE_ROW))->assertOk()->json('data.options'))->not->toBeEmpty();
})->with(['create form' => '&context=create', 'update form' => '&context=update&id={order}']);

it('answers the option search of a Tool Select the user cannot see like an undeclared one', function () {
    $uri = fn (string $attribute, string $row = ''): string => "/martis/api/tools/hfe-tool/fields/{$attribute}/options?search=b{$row}";

    $undeclared = $this->getJson($uri('nope'))->assertStatus(422);

    hfeLikeUndeclared($this->getJson($uri('unit')), $undeclared, 'unit');
    hfeLikeUndeclared($this->getJson($uri('unit', HFE_ROW)), $undeclared, 'unit');

    expect($this->getJson($uri('size'))->assertOk()->json('data.options'))->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Slug check and dependsOn sync
// ---------------------------------------------------------------------------

it('answers the Slug check of a Slug the user cannot see like an undeclared one', function (string $id) {
    $id = str_replace('{order}', (string) $this->order->id, $id);
    $uri = fn (string $attribute): string => "/martis/api/resources/hfe-orders/slug-check/{$attribute}?value=hello{$id}";

    hfeLikeUndeclared($this->getJson($uri('slug')), $this->getJson($uri('nope'))->assertNotFound(), 'slug');

    expect($this->getJson($uri('code'))->assertOk()->json('data.available'))->toBeTrue();
})->with(['create form' => '', 'update form' => '&id={order}']);

it('answers the dependsOn sync of a field the user cannot see like an undeclared one', function (string $context) {
    $body = fn (string $field): array => [
        'field' => $field,
        'formData' => ['name' => 'Changed'],
        'context' => $context,
        'id' => $context === 'update' ? $this->order->id : null,
    ];

    $undeclared = $this->postJson('/martis/api/resources/hfe-orders/sync-field', $body('nope'))->assertStatus(422);

    hfeLikeUndeclared($this->postJson('/martis/api/resources/hfe-orders/sync-field', $body('tier')), $undeclared, 'tier');

    expect($this->postJson('/martis/api/resources/hfe-orders/sync-field', $body('level'))->assertOk()->json('data.placeholder'))
        ->toBe('Reacted');
})->with(['create', 'update']);
