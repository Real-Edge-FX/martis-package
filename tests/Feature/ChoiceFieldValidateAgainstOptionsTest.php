<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Martis\Fields\MultiSelect;
use Martis\Fields\Select;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * `Select::validateAgainstOptions()` and `MultiSelect::validateAgainstOptions()`:
 * the opt-in server-side "value must be one of options()" rule (F078, F106).
 *
 * A choice field never validated against its options: the picker is the only
 * thing that kept a forged value out, so a request that names another role id,
 * or a status the dropdown never offered, was written as sent. The default
 * stays that way (Nova's fields do not validate either, and
 * `Select::allowCustomValues()` relies on it); the opt-in adds one rule over
 * `getOptions()`.
 */
enum SelectValidateStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

/**
 * Validate one value of a field the way a resource form or an Action modal
 * does: with the field's own rules, under its attribute.
 *
 * @return array{passes: bool, errors: list<string>}
 */
function validateSelect(Select|MultiSelect $field, mixed $value, ?string $context = null, bool $present = true): array
{
    $validator = Validator::make(
        $present ? [$field->attribute() => $value] : [],
        [$field->attribute() => $field->buildRules($context)],
        [],
        [$field->attribute() => $field->label()],
    );

    return ['passes' => $validator->passes(), 'errors' => $validator->errors()->get($field->attribute())];
}

it('does not validate against the options by default (documented, unchanged)', function () {
    $field = Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live']);

    expect($field->validatesAgainstOptions())->toBeFalse()
        ->and($field->buildRules())->toBe(['sometimes'])
        ->and(validateSelect($field, 'forged')['passes'])->toBeTrue();
});

it('accepts a value among the options and rejects any other once opted in', function () {
    $field = Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live'])->validateAgainstOptions();

    expect($field->validatesAgainstOptions())->toBeTrue()
        ->and(validateSelect($field, 'draft')['passes'])->toBeTrue()
        ->and(validateSelect($field, 'live')['passes'])->toBeTrue();

    $rejected = validateSelect($field, 'forged');

    expect($rejected['passes'])->toBeFalse()
        // Laravel's own `in` message, named by the field label.
        ->and($rejected['errors'])->toBe(['The selected Status is invalid.']);
});

it('compares by value, not by label', function () {
    $field = Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live'])->validateAgainstOptions();

    expect(validateSelect($field, 'Draft')['passes'])->toBeFalse()
        ->and(validateSelect($field, 'DRAFT')['passes'])->toBeFalse()
        ->and(validateSelect($field, ' draft')['passes'])->toBeFalse();
});

it('matches an integer key against the string a form posts, and nothing looser', function () {
    $field = Select::make('role_id')->options([7 => 'Editor', 9 => 'Viewer'])->validateAgainstOptions();

    expect(validateSelect($field, '7')['passes'])->toBeTrue()
        ->and(validateSelect($field, 7)['passes'])->toBeTrue()
        ->and(validateSelect($field, '07')['passes'])->toBeFalse()
        ->and(validateSelect($field, '7.0')['passes'])->toBeFalse()
        ->and(validateSelect($field, 7.0)['passes'])->toBeFalse()
        ->and(validateSelect($field, true)['passes'])->toBeFalse()
        ->and(validateSelect($field, ['7'])['passes'])->toBeFalse()
        ->and(validateSelect($field, '8')['passes'])->toBeFalse();
});

it('reads every shape options() takes: list, grouped, enum class, collection and closure', function () {
    $list = Select::make('size')->options(['Small', 'Large'])->validateAgainstOptions();
    expect(validateSelect($list, '0')['passes'])->toBeTrue()
        ->and(validateSelect($list, '1')['passes'])->toBeTrue()
        ->and(validateSelect($list, '2')['passes'])->toBeFalse()
        ->and(validateSelect($list, 'Small')['passes'])->toBeFalse();

    $grouped = Select::make('size')->options([
        'MS' => ['label' => 'Small', 'group' => 'Men Sizes'],
        'WS' => ['label' => 'Small', 'group' => 'Women Sizes'],
    ])->validateAgainstOptions();
    expect(validateSelect($grouped, 'MS')['passes'])->toBeTrue()
        ->and(validateSelect($grouped, 'WS')['passes'])->toBeTrue()
        ->and(validateSelect($grouped, 'Men Sizes')['passes'])->toBeFalse();

    $enum = Select::make('status')->options(SelectValidateStatus::class)->validateAgainstOptions();
    expect(validateSelect($enum, 'draft')['passes'])->toBeTrue()
        ->and(validateSelect($enum, 'Draft')['passes'])->toBeFalse();

    $collection = Select::make('owner_id')->options(collect([12 => 'Ana', 15 => 'Rui']))->validateAgainstOptions();
    expect(validateSelect($collection, '15')['passes'])->toBeTrue()
        ->and(validateSelect($collection, '16')['passes'])->toBeFalse();

    $closure = Select::make('owner_id')->options(fn (?Request $request) => collect([12 => 'Ana', 15 => 'Rui']))->validateAgainstOptions();
    expect(validateSelect($closure, '12')['passes'])->toBeTrue()
        ->and(validateSelect($closure, '13')['passes'])->toBeFalse();
});

it('runs a closure of options at validation, never while the rules are built', function () {
    $calls = 0;
    $field = Select::make('owner_id')
        ->options(function () use (&$calls): array {
            $calls++;

            return [12 => 'Ana'];
        })
        ->validateAgainstOptions();

    // The schema serialises the field's rules (`toArray()['rules']`), on every
    // request that lists it: the options query must not run for that.
    $field->buildRules('create');
    $field->buildRules('update');
    expect($calls)->toBe(0);

    validateSelect($field, '12');
    expect($calls)->toBe(1);
});

it('reads the options as they are at validation time, so a changed list changes the verdict', function () {
    $allowed = [12 => 'Ana'];
    $field = Select::make('owner_id')->options(function () use (&$allowed): array {
        return $allowed;
    })->validateAgainstOptions();

    expect(validateSelect($field, '15')['passes'])->toBeFalse();

    $allowed[15] = 'Rui';
    expect(validateSelect($field, '15')['passes'])->toBeTrue();
});

it('leaves an empty value to required and nullable, as every other field does', function () {
    $optional = Select::make('status')->options(['draft' => 'Draft'])->nullable()->validateAgainstOptions();
    expect(validateSelect($optional, null)['passes'])->toBeTrue()
        ->and(validateSelect($optional, '')['passes'])->toBeTrue()
        ->and(validateSelect($optional, null, present: false)['passes'])->toBeTrue();

    $required = Select::make('status')->options(['draft' => 'Draft'])->required()->validateAgainstOptions();
    expect(validateSelect($required, null)['passes'])->toBeFalse()
        ->and(validateSelect($required, '')['passes'])->toBeFalse()
        ->and(validateSelect($required, 'draft')['passes'])->toBeTrue();
});

it('adds the rule to both contexts and keeps the field own rules', function () {
    $field = Select::make('status')
        ->options(['draft' => 'Draft'])
        ->rules(['string', 'max:5'])
        ->creationRules(['required'])
        ->validateAgainstOptions();

    expect(validateSelect($field, 'draft', 'create')['passes'])->toBeTrue()
        ->and(validateSelect($field, 'forged', 'create')['passes'])->toBeFalse()
        ->and(validateSelect($field, 'forged', 'update')['passes'])->toBeFalse()
        ->and(validateSelect($field, 'toolong', 'update')['passes'])->toBeFalse()
        ->and(validateSelect($field, null, 'create', present: false)['passes'])->toBeFalse();
});

it('can be turned off again', function () {
    $field = Select::make('status')->options(['draft' => 'Draft'])->validateAgainstOptions()->validateAgainstOptions(false);

    expect($field->validatesAgainstOptions())->toBeFalse()
        ->and(validateSelect($field, 'forged')['passes'])->toBeTrue();
});

it('still rejects a typed value when allowCustomValues() is also on: the closed list wins', function () {
    // The two contradict. Failing closed is the safe reading of the flag a
    // developer set to stop forged values; the docs say to pick one.
    $field = Select::make('model')
        ->options(['gpt-4o' => 'gpt-4o'])
        ->allowCustomValues()
        ->validateAgainstOptions();

    expect(validateSelect($field, 'gpt-4o')['passes'])->toBeTrue()
        ->and(validateSelect($field, 'typed-by-hand')['passes'])->toBeFalse();
});

it('serialises the field without choking on the rule', function () {
    $field = Select::make('status')->options(['draft' => 'Draft'])->validateAgainstOptions();

    expect(json_encode($field->toArray()))->toBeString();
});

// ---------------------------------------------------------------------------
// MultiSelect: every selected value must be an option
// ---------------------------------------------------------------------------

it('does not validate a MultiSelect against the options by default', function () {
    $field = MultiSelect::make('tags')->options(['php' => 'PHP', 'go' => 'Go']);

    expect($field->validatesAgainstOptions())->toBeFalse()
        ->and(validateSelect($field, ['forged'])['passes'])->toBeTrue();
});

it('accepts a MultiSelect whose values are all options and rejects one stray value', function () {
    $field = MultiSelect::make('tags')->options(['php' => 'PHP', 'go' => 'Go'])->validateAgainstOptions();

    expect(validateSelect($field, ['php'])['passes'])->toBeTrue()
        ->and(validateSelect($field, ['php', 'go'])['passes'])->toBeTrue()
        ->and(validateSelect($field, [])['passes'])->toBeTrue()
        ->and(validateSelect($field, ['php', 'forged'])['passes'])->toBeFalse()
        ->and(validateSelect($field, ['PHP'])['passes'])->toBeFalse();

    expect(validateSelect($field, ['php', 'forged'])['errors'])->toBe(['The selected Tags is invalid.']);
});

it('rejects a MultiSelect value that is not a list of strings and integers', function (mixed $value) {
    $field = MultiSelect::make('tags')->options([7 => 'Seven', 'go' => 'Go'])->validateAgainstOptions();

    expect(validateSelect($field, $value)['passes'])->toBeFalse();
})->with([
    'a bare string' => ['go'],
    'a nested array' => [[['go']]],
    'a boolean item' => [[true]],
    'a float item' => [[7.0]],
    'a null item' => [[null]],
    'a padded integer' => [['07']],
]);

it('accepts an integer key as the string a form posts, in a MultiSelect', function () {
    $field = MultiSelect::make('role_ids')->options([7 => 'Editor', 9 => 'Viewer'])->validateAgainstOptions();

    expect(validateSelect($field, ['7', 9])['passes'])->toBeTrue()
        ->and(validateSelect($field, ['7', '8'])['passes'])->toBeFalse();
});

it('leaves an empty MultiSelect to required and nullable', function () {
    $optional = MultiSelect::make('tags')->options(['php' => 'PHP'])->nullable()->validateAgainstOptions();
    expect(validateSelect($optional, null)['passes'])->toBeTrue()
        ->and(validateSelect($optional, [])['passes'])->toBeTrue();

    $required = MultiSelect::make('tags')->options(['php' => 'PHP'])->required()->validateAgainstOptions();
    expect(validateSelect($required, [])['passes'])->toBeFalse()
        ->and(validateSelect($required, ['php'])['passes'])->toBeTrue();
});

it('runs a MultiSelect closure of options at validation, never while the rules are built', function () {
    $calls = 0;
    $field = MultiSelect::make('owner_ids')
        ->options(function () use (&$calls): array {
            $calls++;

            return [12 => 'Ana'];
        })
        ->validateAgainstOptions();

    $field->buildRules('create');
    expect($calls)->toBe(0);

    validateSelect($field, ['12']);
    expect($calls)->toBe(1);
});

// ---------------------------------------------------------------------------
// On the resource endpoints: a forged value is a 422 and nothing is written
// ---------------------------------------------------------------------------

class CfvPost extends Model
{
    protected $table = 'cfv_posts';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['tags' => 'array'];
}

class CfvPostResource extends Resource
{
    public static function model(): string
    {
        return CfvPost::class;
    }

    public static function uriKey(): string
    {
        return 'cfv-posts';
    }

    public function fields(Request $request): array
    {
        return [
            Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live'])->nullable()->validateAgainstOptions(),
            Select::make('free_status')->options(['draft' => 'Draft', 'live' => 'Live'])->nullable(),
            MultiSelect::make('tags')->options(['php' => 'PHP', 'go' => 'Go'])->nullable()->validateAgainstOptions(),
        ];
    }
}

describe('on the resource endpoints', function () {
    beforeEach(function () {
        $this->withoutMiddleware(MartisAuthenticate::class);

        Schema::dropIfExists('cfv_posts');
        Schema::create('cfv_posts', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->string('free_status')->nullable();
            $table->json('tags')->nullable();
        });

        $registry = app(ResourceRegistry::class);
        $registry->flush();
        $registry->register(CfvPostResource::class);
    });

    afterEach(function () {
        Schema::dropIfExists('cfv_posts');
        app(ResourceRegistry::class)->flush();
    });

    it('answers 422 on the field and writes no record for a forged value on create', function (array $payload, string $field) {
        $this->postJson('/martis/api/resources/cfv-posts', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', $field)
            ->assertJsonPath('errors.0.message', 'The selected '.ucfirst($field).' is invalid.');

        expect(CfvPost::query()->count())->toBe(0);
    })->with([
        'a Select value outside the options' => [['status' => 'forged'], 'status'],
        'a MultiSelect value outside the options' => [['tags' => ['php', 'forged']], 'tags'],
    ]);

    it('writes a value among the options on create', function () {
        $this->postJson('/martis/api/resources/cfv-posts', ['status' => 'live', 'tags' => ['go']])->assertStatus(201);

        expect(CfvPost::query()->sole()->only(['status', 'tags']))->toBe(['status' => 'live', 'tags' => ['go']]);
    });

    it('answers 422 and leaves the stored value on a forged update', function () {
        $post = CfvPost::create(['status' => 'draft', 'tags' => ['php']]);

        $this->putJson("/martis/api/resources/cfv-posts/{$post->id}", ['status' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'status');
        $this->putJson("/martis/api/resources/cfv-posts/{$post->id}", ['tags' => ['rust']])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'tags');

        expect($post->fresh()->only(['status', 'tags']))->toBe(['status' => 'draft', 'tags' => ['php']]);
    });

    it('skips the rule on an update that does not send the field, as every rule is', function () {
        $post = CfvPost::create(['status' => 'draft']);

        $this->putJson("/martis/api/resources/cfv-posts/{$post->id}", ['free_status' => 'live'])->assertOk();

        expect($post->fresh()->only(['status', 'free_status']))->toBe(['status' => 'draft', 'free_status' => 'live']);
    });

    it('leaves a Select that did not opt in as it was: any value is written', function () {
        $this->postJson('/martis/api/resources/cfv-posts', ['free_status' => 'whatever'])->assertStatus(201);

        expect(CfvPost::query()->sole()->free_status)->toBe('whatever');
    });
});
