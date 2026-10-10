<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// v2.10.0: a field whose only "required" rule is a conditional sibling
// (`required_if`, ...) is required only when the condition holds. The old
// prefix check treated it as an unconditional `required`, so two mutually
// exclusive conditional fields made every create fail with a 422.

class CrrContact extends Model
{
    protected $table = 'crr_contacts';

    protected $guarded = [];

    public $timestamps = false;
}

class CrrContactResource extends Resource
{
    public static function model(): string
    {
        return CrrContact::class;
    }

    public static function uriKey(): string
    {
        return 'crr-contacts';
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('type')->options(['person' => 'Person', 'company' => 'Company'])->rules(['required']),
            Text::make('first_name')->rules(['nullable', 'string', 'required_if:type,person']),
            Text::make('legal_name')->rules(['nullable', 'string', 'required_if:type,company']),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('crr_contacts');
    Schema::create('crr_contacts', function ($t) {
        $t->id();
        $t->string('type')->nullable();
        $t->string('first_name')->nullable();
        $t->string('legal_name')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(CrrContactResource::class);
});

afterEach(function () {
    Schema::dropIfExists('crr_contacts');
    app(ResourceRegistry::class)->flush();
});

it('creates a person without the company-only field', function () {
    $this->postJson('/martis/api/resources/crr-contacts', ['type' => 'person', 'first_name' => 'Ana'])->assertCreated();

    expect(CrrContact::query()->count())->toBe(1);
});

it('creates a company without the person-only field', function () {
    $this->postJson('/martis/api/resources/crr-contacts', ['type' => 'company', 'legal_name' => 'Acme'])->assertCreated();

    expect(CrrContact::query()->count())->toBe(1);
});

it('still refuses a person without first_name, naming that field only', function () {
    $response = $this->postJson('/martis/api/resources/crr-contacts', ['type' => 'person']);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->pluck('field')->all())->toBe(['first_name'])
        ->and(CrrContact::query()->count())->toBe(0);
});

it('does not flag a conditional field as required in the schema', function () {
    $fields = collect($this->getJson('/martis/api/resources/crr-contacts/schema')->json('data.fields') ?? []);

    expect($fields->isNotEmpty())->toBeTrue()
        ->and($fields->firstWhere('attribute', 'type')['required'])->toBeTrue()
        ->and($fields->firstWhere('attribute', 'first_name')['required'])->toBeFalse();
});
