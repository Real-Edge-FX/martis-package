<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Cache\MartisCache;
use Martis\Concerns\Actionable;
use Martis\Fields\MorphMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Panel;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

// ===========================================================================
// The detail page of a model that uses the Actionable trait ends with an
// "Action Events" panel, as in Nova (`ResolvesFields::shouldAddActionsField()`
// / `actionEventsField()`): a collapsable MorphMany of `actions()` through
// the action event resource, added unless the detail fields already declare
// one, and seen only by a user that resource lets viewAny (the audit log is
// closed until the host opens it).
// ===========================================================================

class AEPUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class AEPInvoice extends Model
{
    use Actionable;

    protected $table = 'aep_invoices';

    protected $guarded = [];

    public $timestamps = false;
}

/** The same table, without the trait. */
class AEPPlainInvoice extends Model
{
    protected $table = 'aep_invoices';

    protected $guarded = [];

    public $timestamps = false;
}

class AEPInvoiceResource extends Resource
{
    public static function model(): string
    {
        return AEPInvoice::class;
    }

    public static function uriKey(): string
    {
        return 'aep-invoices';
    }

    public function fields(Request $request): array
    {
        return [Text::make('number')];
    }
}

/** Declares its own panel for the log, inside a Panel. */
class AEPDeclaredInvoiceResource extends AEPInvoiceResource
{
    public static function uriKey(): string
    {
        return 'aep-declared-invoices';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('number'),
            Panel::make('History', [MorphMany::make('History', 'actions', ActionEventResource::class)]),
        ];
    }
}

/** Opts out, as a Nova resource overriding shouldAddActionsField(). */
class AEPOptOutInvoiceResource extends AEPInvoiceResource
{
    public static function uriKey(): string
    {
        return 'aep-opt-out-invoices';
    }

    protected function shouldAddActionsField(Request $request, array $fields): bool
    {
        return false;
    }
}

class AEPPlainInvoiceResource extends AEPInvoiceResource
{
    public static function model(): string
    {
        return AEPPlainInvoice::class;
    }

    public static function uriKey(): string
    {
        return 'aep-plain-invoices';
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->timestamps();
        });
    }

    Schema::dropIfExists('aep_invoices');
    Schema::create('aep_invoices', function ($t) {
        $t->id();
        $t->string('number');
    });

    Schema::dropIfExists('martis_action_events');
    Schema::create('martis_action_events', function ($t) {
        $t->id();
        $t->string('batch_id')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('name');
        $t->string('actionable_type')->nullable();
        $t->string('actionable_id')->nullable();
        $t->string('target_type')->nullable();
        $t->string('target_id')->nullable();
        $t->string('model_type')->nullable();
        $t->string('model_id')->nullable();
        $t->text('fields')->nullable();
        $t->string('status')->default('completed');
        $t->text('exception')->nullable();
        $t->text('original')->nullable();
        $t->text('changes')->nullable();
        $t->timestamps();
    });

    $this->actingAs(AEPUser::query()->create(['name' => 'Operator', 'email' => 'aep-operator@example.com', 'password' => 'x']), 'web');

    $this->invoice = AEPInvoice::query()->create(['number' => 'INV-1']);
    ActionEvent::query()->create([
        'batch_id' => 'b', 'user_id' => 1, 'name' => 'Mark Paid',
        'actionable_type' => AEPInvoice::class, 'actionable_id' => (string) $this->invoice->id,
        'target_type' => AEPInvoice::class, 'target_id' => (string) $this->invoice->id,
        'model_type' => AEPInvoice::class, 'model_id' => (string) $this->invoice->id,
        'fields' => [], 'status' => 'finished', 'exception' => '', 'original' => [], 'changes' => [],
    ]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([AEPInvoiceResource::class, AEPDeclaredInvoiceResource::class, AEPOptOutInvoiceResource::class, AEPPlainInvoiceResource::class, ActionEventResource::class] as $class) {
        $registry->register($class);
    }

    Resource::flushPolicyCache();
});

afterEach(function () {
    Schema::dropIfExists('aep_invoices');
    Schema::dropIfExists('martis_action_events');
    app(ResourceRegistry::class)->flush();
    Resource::flushPolicyCache();
});

function aepOpenLog(): void
{
    Gate::define(ActionEventResource::GATE, fn ($user = null): bool => $user !== null);
}

/** @return list<array<string, mixed>> The action-event panels of the detail page. */
function aepLogPanels(string $uriKey): array
{
    app(MartisCache::class)->clear('schema');

    $flatten = function (array $items) use (&$flatten): array {
        $out = [];
        foreach ($items as $item) {
            $out[] = $item;
            foreach (['fields', 'tabs', 'items'] as $key) {
                if (isset($item[$key]) && is_array($item[$key])) {
                    array_push($out, ...$flatten($item[$key]));
                }
            }
        }

        return $out;
    };

    return array_values(array_filter(
        $flatten(test()->getJson("/martis/api/resources/{$uriKey}/schema")->assertOk()->json('data.fieldsForDetail')),
        fn (array $field): bool => ($field['relationship'] ?? null) === 'actions',
    ));
}

it('adds an Action Events panel at the bottom of an Actionable model detail page once the log is open', function () {
    expect(aepLogPanels('aep-invoices'))->toBe([]);

    aepOpenLog();

    $panels = aepLogPanels('aep-invoices');
    expect($panels)->toHaveCount(1)
        ->and($panels[0]['type'])->toBe('morph_many')
        ->and($panels[0]['label'] ?? $panels[0]['name'] ?? null)->toBe('Action Events');

    app(MartisCache::class)->clear('schema');
    $detail = $this->getJson('/martis/api/resources/aep-invoices/schema')->json('data.fieldsForDetail');
    expect(end($detail)['relationship'] ?? null)->toBe('actions');
});

it('lists the record action events through the added panel, and answers 403 while the log is closed', function () {
    $url = '/martis/api/resources/aep-invoices/'.$this->invoice->id.'/morph-many/actions';

    $this->getJson($url)->assertForbidden();

    aepOpenLog();

    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mark Paid');
});

it('does not add a second panel when the resource declares one for the log', function () {
    aepOpenLog();

    $panels = aepLogPanels('aep-declared-invoices');

    expect($panels)->toHaveCount(1)
        ->and($panels[0]['label'] ?? $panels[0]['name'] ?? null)->toBe('History');
});

it('lets a resource opt out through shouldAddActionsField()', function () {
    aepOpenLog();

    expect(aepLogPanels('aep-opt-out-invoices'))->toBe([]);
});

it('adds no panel to a model without the Actionable trait', function () {
    aepOpenLog();

    expect(aepLogPanels('aep-plain-invoices'))->toBe([]);
});

it('adds no panel when no resource exposes the action event model', function () {
    aepOpenLog();
    app(ResourceRegistry::class)->flush();
    app(ResourceRegistry::class)->register(AEPInvoiceResource::class);

    expect(aepLogPanels('aep-invoices'))->toBe([]);
});
