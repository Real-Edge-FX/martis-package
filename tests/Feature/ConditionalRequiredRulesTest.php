<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Conditional `required_*` rules (v1.39.6).
//
// A field is required (asterisk, a literal `required` in the rule set) only
// when its rules hold the unconditional validator: the exact string
// `required`, or an object whose string form is exactly `required`
// (`Rule::requiredIf(true)`), as in Nova. `required_if`, `required_with`,
// `required_unless` and the like were matched by prefix and made the field
// mandatory on every request.
// ===========================================================================

class CRRContactModel extends Model
{
    protected $table = 'crr_contacts';

    protected $guarded = [];

    public $timestamps = false;
}

class CRRContactResource extends Resource
{
    public static function model(): string
    {
        return CRRContactModel::class;
    }

    public static function uriKey(): string
    {
        return 'crr-contacts';
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('type')->options(['person' => 'Person', 'company' => 'Company'])->rules(['required', Rule::in(['person', 'company'])]),
            Text::make('first_name')->rules(['nullable', 'string', 'required_if:type,person']),
            Text::make('legal_name')->rules(['nullable', 'string', 'required_if:type,company']),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('crr_contacts');
    Schema::create('crr_contacts', function ($table) {
        $table->id();
        $table->string('type')->nullable();
        $table->string('first_name')->nullable();
        $table->string('legal_name')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CRRContactResource::class);
});

afterEach(function () {
    Schema::dropIfExists('crr_contacts');
});

it('does not flag a conditional required rule as required', function (string|object $rule) {
    $field = Text::make('name')->rules(['nullable', $rule]);

    expect($field->isRequired())->toBeFalse()
        ->and($field->buildRules('create'))->not->toContain('required');
})->with([
    'required_if' => 'required_if:type,person',
    'required_with' => 'required_with:other',
    'required_unless' => 'required_unless:type,person',
    'required_array_keys' => 'required_array_keys:a',
    'Rule::requiredIf(false)' => fn () => Rule::requiredIf(false),
    'Rule::in with the word' => fn () => Rule::in(['required', 'x']),
]);

it('flags the unconditional required rule as required', function (string|object $rule) {
    $field = Text::make('name')->rules([$rule]);

    expect($field->isRequired())->toBeTrue();
})->with([
    'required' => 'required',
    'Rule::requiredIf(true)' => fn () => Rule::requiredIf(true),
]);

it('creates a person and a company through the resource endpoint', function () {
    $this->postJson('/martis/api/resources/crr-contacts', ['type' => 'person', 'first_name' => 'Ana'])->assertCreated();
    $this->postJson('/martis/api/resources/crr-contacts', ['type' => 'company', 'legal_name' => 'Acme'])->assertCreated();

    expect(CRRContactModel::count())->toBe(2);
});

it('still requires the field its condition names', function () {
    $response = $this->postJson('/martis/api/resources/crr-contacts', ['type' => 'person']);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toBe(['first_name']);
});
