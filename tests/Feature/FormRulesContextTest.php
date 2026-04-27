<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

class FormRulesTestModel extends Model
{
    protected $table = 'form_rules_items';

    protected $guarded = [];

    public $timestamps = false;
}

class FormRulesTestResource extends Resource
{
    public static function model(): string
    {
        return FormRulesTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            // Password is required on create, optional on update — the
            // canonical use-case for the new context-aware rule API.
            Text::make('password')
                ->rules(['min:8'])
                ->creationRules(['required'])
                ->updateRules(['nullable']),

            // Slug is immutable — settable on create, ignored on update
            // (the controller silently skips the fill).
            Text::make('slug')->immutable()->required(),

            // Title is plain.
            Text::make('title')->required(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('form_rules_items');
    Schema::create('form_rules_items', function ($table) {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->string('password')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(FormRulesTestResource::class);
});

afterEach(function () {
    Schema::dropIfExists('form_rules_items');
});

// -----------------------------------------------------------------------------
// creationRules / updateRules
// -----------------------------------------------------------------------------

it('creation rules apply when posting to create — password is required', function () {
    $response = $this->postJson('/martis/api/resources/form-rules-test-models', [
        'title' => 'A',
        'slug' => 'a',
        // password missing
    ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toBeArray();
});

it('creation rules do NOT apply on update — password can be omitted', function () {
    $row = FormRulesTestModel::create(['title' => 'X', 'slug' => 'x', 'password' => 'verysecret']);

    $response = $this->putJson("/martis/api/resources/form-rules-test-models/{$row->id}", [
        'title' => 'X updated',
        // password missing — should pass because creationRules don't apply on update
    ]);

    $response->assertStatus(200);

    $row->refresh();
    expect($row->title)->toBe('X updated');
    // Password unchanged.
    expect($row->password)->toBe('verysecret');
});

it('rules() base validation still applies on every context', function () {
    $row = FormRulesTestModel::create(['title' => 'X', 'slug' => 'x', 'password' => 'verysecret']);

    // Provide a too-short password — base `min:8` should reject on update.
    $response = $this->putJson("/martis/api/resources/form-rules-test-models/{$row->id}", [
        'password' => 'short',
    ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toBeArray();
});

// -----------------------------------------------------------------------------
// immutable()
// -----------------------------------------------------------------------------

it('immutable fields accept the value on create', function () {
    $response = $this->postJson('/martis/api/resources/form-rules-test-models', [
        'title' => 'Y',
        'slug' => 'original-slug',
        'password' => 'verysecret',
    ]);

    $response->assertStatus(201);

    $row = FormRulesTestModel::query()->latest('id')->first();
    expect($row->slug)->toBe('original-slug');
});

it('immutable fields silently ignore writes on update', function () {
    $row = FormRulesTestModel::create([
        'title' => 'Z',
        'slug' => 'frozen',
        'password' => 'verysecret',
    ]);

    $response = $this->putJson("/martis/api/resources/form-rules-test-models/{$row->id}", [
        'title' => 'Z updated',
        'slug' => 'tampered-slug',
    ]);

    $response->assertStatus(200);

    $row->refresh();
    expect($row->title)->toBe('Z updated');
    // Slug stays at the original value despite the request trying to change it.
    expect($row->slug)->toBe('frozen');
});
