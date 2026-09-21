<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Number;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

class DependsOnTestModel extends Model
{
    protected $table = 'depends_on_items';

    protected $guarded = [];

    public $timestamps = false;
}

class DependsOnTestResource extends Resource
{
    public static function model(): string
    {
        return DependsOnTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            // Independent toggle field.
            Text::make('plan'),

            // Reactive: required only when plan = "paid".
            Number::make('price')->dependsOn(['plan'], function (array $form, Request $r, Number $field) {
                $field->required(($form['plan'] ?? null) === 'paid');
                if (($form['plan'] ?? null) === 'paid') {
                    $field->placeholder('Set the monthly price.');
                }
            }),

            // Reactive Select with closure-loaded options that depend on plan.
            Select::make('billing_cycle')->dependsOn(['plan'], function (array $form, Request $r, Select $field) {
                if (($form['plan'] ?? null) === 'paid') {
                    $field->options(['Monthly' => 'monthly', 'Yearly' => 'yearly']);
                } else {
                    $field->options(['Free tier' => 'free']);
                }
            }),
        ];
    }
}

class DependsOnStandardPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }

    public function create($user): bool
    {
        return true;
    }

    // Standard Laravel shape: the model is REQUIRED. A gate calling
    // update($user) alone raises ArgumentCountError.
    public function update($user, Model $model): bool
    {
        return $model->plan === 'editable';
    }
}

class DependsOnPolicyResource extends DependsOnTestResource
{
    public static ?string $policy = DependsOnStandardPolicy::class;
}

class DependsOnNestedResource extends Resource
{
    public static function model(): string
    {
        return DependsOnTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('plan'),
            Section::make('Pricing', [
                Number::make('price')->dependsOn(['plan'], function (array $form, Request $r, Number $field) {
                    $field->required(($form['plan'] ?? null) === 'paid');
                }),
            ]),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('depends_on_items');
    Schema::create('depends_on_items', function ($table) {
        $table->id();
        $table->string('plan')->nullable();
        $table->integer('price')->nullable();
        $table->string('billing_cycle')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(DependsOnTestResource::class);
});

afterEach(function () {
    Schema::dropIfExists('depends_on_items');
});

// -----------------------------------------------------------------------------
// schema serialization
// -----------------------------------------------------------------------------

it('schema exposes the dependsOn declaration on reactive fields', function () {
    $response = $this->getJson('/martis/api/resources/depends-on-test-models/schema');
    $response->assertOk();

    $fields = collect($response->json('data.fields'));
    $price = $fields->firstWhere('attribute', 'price');
    $cycle = $fields->firstWhere('attribute', 'billing_cycle');
    $plan = $fields->firstWhere('attribute', 'plan');

    expect($price['dependsOn'])->toEqual(['fields' => ['plan']]);
    expect($cycle['dependsOn'])->toEqual(['fields' => ['plan']]);
    // Non-reactive field keeps `dependsOn` null.
    expect($plan['dependsOn'])->toBeNull();
});

// -----------------------------------------------------------------------------
// sync-field endpoint
// -----------------------------------------------------------------------------

it('sync-field re-runs the closure with the live form payload', function () {
    $response = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => 'price',
        'context' => 'create',
        'formData' => ['plan' => 'paid'],
    ]);

    $response->assertOk();
    expect($response->json('data.attribute'))->toBe('price');
    // Closure flipped required + placeholder when plan = "paid".
    expect($response->json('data.required'))->toBeTrue();
    expect($response->json('data.placeholder'))->toBe('Set the monthly price.');
});

it('sync-field reflects opposite branch when watched value changes', function () {
    $response = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => 'price',
        'context' => 'create',
        'formData' => ['plan' => 'free'],
    ]);

    $response->assertOk();
    expect($response->json('data.required'))->toBeFalse();
    expect($response->json('data.placeholder'))->toBeNull();
});

it('sync-field can replace Select options based on a sibling value', function () {
    $paid = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => 'billing_cycle',
        'context' => 'create',
        'formData' => ['plan' => 'paid'],
    ]);
    $free = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => 'billing_cycle',
        'context' => 'create',
        'formData' => ['plan' => 'free'],
    ]);

    $paid->assertOk();
    $free->assertOk();

    expect(collect($paid->json('data.options'))->pluck('value')->all())
        ->toEqual(['monthly', 'yearly']);
    expect(collect($free->json('data.options'))->pluck('value')->all())
        ->toEqual(['free']);
});

it('sync-field rejects an unknown field attribute', function () {
    $response = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => 'nonexistent_field',
        'context' => 'create',
        'formData' => [],
    ]);

    $response->assertStatus(422);
});

it('sync-field rejects a non-reactive field attribute', function () {
    // `plan` exists but has no dependsOn() — must not be syncable.
    $response = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => 'plan',
        'context' => 'create',
        'formData' => [],
    ]);

    $response->assertStatus(422);
});

it('sync-field rejects an empty field attribute', function () {
    $response = $this->postJson('/martis/api/resources/depends-on-test-models/sync-field', [
        'field' => '',
        'context' => 'create',
        'formData' => [],
    ]);

    $response->assertStatus(422);
});

// -----------------------------------------------------------------------------
// update context binds the record before gating (v1.37.0)
// -----------------------------------------------------------------------------

it('sync-field in the update context binds the record so a standard policy receives the model', function () {
    $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Test User']));
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(DependsOnPolicyResource::class);
    $editable = DependsOnTestModel::create(['plan' => 'editable']);
    $locked = DependsOnTestModel::create(['plan' => 'locked']);
    $url = '/martis/api/resources/'.DependsOnPolicyResource::uriKey().'/sync-field';

    $this->postJson($url, ['field' => 'price', 'context' => 'update', 'id' => $editable->id, 'formData' => ['plan' => 'paid']])
        ->assertOk();
    $this->postJson($url, ['field' => 'price', 'context' => 'update', 'id' => $locked->id, 'formData' => []])
        ->assertStatus(403);
    $this->postJson($url, ['field' => 'price', 'context' => 'update', 'formData' => []])
        ->assertStatus(422);
    $this->postJson($url, ['field' => 'price', 'context' => 'update', 'id' => 999999, 'formData' => []])
        ->assertStatus(404);

    Martis\Resource::flushPolicyCache();
});

it('sync-field finds a reactive field nested inside a layout container', function () {
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(DependsOnNestedResource::class);

    $response = $this->postJson('/martis/api/resources/'.DependsOnNestedResource::uriKey().'/sync-field', [
        'field' => 'price',
        'context' => 'create',
        'formData' => ['plan' => 'paid'],
    ]);

    $response->assertOk();
    expect($response->json('data.required'))->toBeTrue();
});
