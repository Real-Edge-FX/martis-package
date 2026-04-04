<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\BelongsTo;
use Martis\Fields\Boolean;
use Martis\Fields\Date;
use Martis\Fields\Email;
use Martis\Fields\Heading;
use Martis\Fields\Hidden;
use Martis\Fields\Id;
use Martis\Fields\Number;
use Martis\Fields\Password;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Fields\Textarea;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class FieldTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['title', 'body', 'count', 'active', 'status', 'published_at', 'author_id'];

    public $timestamps = false;
}

class AuthorModel extends Model
{
    protected $table = 'users';

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Text
// ---------------------------------------------------------------------------

it('Text::make creates a text field', function () {
    $field = Text::make('title');

    expect($field->attribute())->toBe('title')
        ->and($field->label())->toBe('Title')
        ->and($field->type())->toBe('text');
});

it('Text::make accepts custom label', function () {
    $field = Text::make('title', 'Post Title');

    expect($field->label())->toBe('Post Title');
});

it('Text resolves attribute from model', function () {
    $model = new FieldTestModel(['title' => 'Hello World']);
    $field = Text::make('title');

    expect($field->resolve($model))->toBe('Hello World');
});

it('Text fills model attribute', function () {
    $model = new FieldTestModel;
    $field = Text::make('title');
    $field->fill($model, 'New Title');

    expect($model->getAttribute('title'))->toBe('New Title');
});

it('Text toArray contains required keys', function () {
    $field = Text::make('title')->sortable()->searchable()->required();
    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'nullable', 'readonly', 'required', 'sortable', 'searchable', 'rules'])
        ->and($arr['sortable'])->toBeTrue()
        ->and($arr['searchable'])->toBeTrue()
        ->and($arr['rules'])->toContain('required');
});

// ---------------------------------------------------------------------------
// Textarea
// ---------------------------------------------------------------------------

it('Textarea::make creates a textarea field', function () {
    $field = Textarea::make('body');

    expect($field->type())->toBe('textarea')
        ->and($field->getRows())->toBe(5);
});

it('Textarea rows() sets row count', function () {
    $field = Textarea::make('body')->rows(10);

    expect($field->getRows())->toBe(10)
        ->and($field->toArray()['rows'])->toBe(10);
});

// ---------------------------------------------------------------------------
// Number
// ---------------------------------------------------------------------------

it('Number::make creates a number field', function () {
    $field = Number::make('count');

    expect($field->type())->toBe('number');
});

it('Number min/max/step appear in toArray', function () {
    $field = Number::make('count')->min(1)->max(100)->step(5);
    $arr = $field->toArray();

    expect($arr['min'])->toBe(1)
        ->and($arr['max'])->toBe(100)
        ->and($arr['step'])->toBe(5);
});

it('Number min/max add validation rules', function () {
    $field = Number::make('count')->min(0)->max(99);
    $rules = $field->buildRules();

    expect($rules)->toContain('min:0')
        ->and($rules)->toContain('max:99');
});

it('Number integer() adds integer rule', function () {
    $field = Number::make('count')->integer();

    expect($field->buildRules())->toContain('integer');
});

// ---------------------------------------------------------------------------
// Boolean
// ---------------------------------------------------------------------------

it('Boolean::make creates a boolean field', function () {
    $field = Boolean::make('active');

    expect($field->type())->toBe('boolean');
});

it('Boolean resolves attribute as strict bool', function () {
    $model = new FieldTestModel(['active' => 1]);
    $field = Boolean::make('active');

    expect($field->resolve($model))->toBeTrue();
});

it('Boolean fills model with cast bool', function () {
    $model = new FieldTestModel;
    $field = Boolean::make('active');
    $field->fill($model, 0);

    expect($model->getAttribute('active'))->toBeFalse();
});

it('Boolean trueLabel/falseLabel appear in toArray', function () {
    $field = Boolean::make('active')->trueLabel('On')->falseLabel('Off');
    $arr = $field->toArray();

    expect($arr['trueLabel'])->toBe('On')
        ->and($arr['falseLabel'])->toBe('Off');
});

// ---------------------------------------------------------------------------
// Select
// ---------------------------------------------------------------------------

it('Select::make creates a select field', function () {
    $field = Select::make('status');

    expect($field->type())->toBe('select');
});

it('Select options() normalizes associative array', function () {
    $field = Select::make('status')->options(['Active' => 1, 'Inactive' => 0]);
    $options = $field->getOptions();

    expect($options)->toHaveCount(2)
        ->and($options[0])->toBe(['label' => 'Active', 'value' => 1])
        ->and($options[1])->toBe(['label' => 'Inactive', 'value' => 0]);
});

it('Select options() normalizes sequential array', function () {
    $field = Select::make('status')->options(['draft', 'published']);
    $options = $field->getOptions();

    expect($options[0])->toBe(['label' => 'draft', 'value' => 'draft'])
        ->and($options[1])->toBe(['label' => 'published', 'value' => 'published']);
});

it('Select options appear in toArray', function () {
    $field = Select::make('status')->options(['Draft' => 'draft']);
    $arr = $field->toArray();

    expect($arr['options'])->toHaveCount(1)
        ->and($arr['options'][0]['label'])->toBe('Draft');
});

// ---------------------------------------------------------------------------
// Date
// ---------------------------------------------------------------------------

it('Date::make creates a date field', function () {
    $field = Date::make('published_at');

    expect($field->type())->toBe('date');
});

it('Date resolves Carbon to formatted string', function () {
    $date = new DateTime('2025-06-15 14:30:00');
    $model = new FieldTestModel(['published_at' => $date]);
    $field = Date::make('published_at');

    expect($field->resolve($model))->toBe('2025-06-15');
});

it('Date withTime changes format to include time', function () {
    $date = new DateTime('2025-06-15 14:30:00');
    $model = new FieldTestModel(['published_at' => $date]);
    $field = Date::make('published_at')->withTime();

    expect($field->resolve($model))->toBe('2025-06-15 14:30:00')
        ->and($field->toArray()['withTime'])->toBeTrue();
});

it('Date resolves null to null', function () {
    $model = new FieldTestModel(['published_at' => null]);
    $field = Date::make('published_at')->nullable();

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// BelongsTo
// ---------------------------------------------------------------------------

it('BelongsTo::make creates a belongs_to field', function () {
    $field = BelongsTo::make('author');

    expect($field->type())->toBe('belongs_to')
        ->and($field->attribute())->toBe('author_id');
});

it('BelongsTo::make derives foreign key from relationship name', function () {
    $field = BelongsTo::make('category');
    $arr = $field->toArray();

    expect($arr['foreignKey'])->toBe('category_id')
        ->and($arr['relationship'])->toBe('category');
});

it('BelongsTo resolves foreign key value when relation not loaded', function () {
    $model = new FieldTestModel(['author_id' => 42]);
    $field = BelongsTo::make('author');

    $resolved = $field->resolve($model);

    expect($resolved)->toBeArray()
        ->and($resolved['id'])->toBe(42)
        ->and($resolved['title'])->toBeNull();
});

it('BelongsTo returns null when FK is null', function () {
    $model = new FieldTestModel(['author_id' => null]);
    $field = BelongsTo::make('author');

    expect($field->resolve($model))->toBeNull();
});

it('BelongsTo fills foreign key from raw ID', function () {
    $model = new FieldTestModel;
    $field = BelongsTo::make('author');
    $field->fill($model, 7);

    expect($model->getAttribute('author_id'))->toBe(7);
});

it('BelongsTo fills foreign key from array with id key', function () {
    $model = new FieldTestModel;
    $field = BelongsTo::make('author');
    $field->fill($model, ['id' => 99, 'title' => 'Jane']);

    expect($model->getAttribute('author_id'))->toBe(99);
});

it('BelongsTo titleAttribute and relatedResource appear in toArray', function () {
    $field = BelongsTo::make('author')
        ->titleAttribute('full_name')
        ->relatedResource('users');

    $arr = $field->toArray();

    expect($arr['titleAttribute'])->toBe('full_name')
        ->and($arr['relatedUriKey'])->toBe('users');
});

// ---------------------------------------------------------------------------
// Field base — visibility rules
// ---------------------------------------------------------------------------

it('fields are visible everywhere by default', function () {
    $field = Text::make('title');

    expect($field->isShownOnIndex())->toBeTrue()
        ->and($field->isShownOnDetail())->toBeTrue()
        ->and($field->isShownOnForms())->toBeTrue();
});

it('hideFromIndex hides field from index', function () {
    $field = Text::make('title')->hideFromIndex();

    expect($field->isShownOnIndex())->toBeFalse()
        ->and($field->isShownOnDetail())->toBeTrue();
});

it('showOnIndex restores index visibility', function () {
    $field = Text::make('title')->hideFromIndex()->showOnIndex();

    expect($field->isShownOnIndex())->toBeTrue();
});

it('hideFromDetail hides field from detail', function () {
    $field = Text::make('title')->hideFromDetail();

    expect($field->isShownOnDetail())->toBeFalse();
});

it('hideFromForms hides field from forms', function () {
    $field = Text::make('title')->hideFromForms();

    expect($field->isShownOnForms())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Field base — validation rules
// ---------------------------------------------------------------------------

it('required() adds required rule', function () {
    $field = Text::make('title')->required();

    expect($field->buildRules())->toContain('required');
});

it('nullable() adds nullable rule', function () {
    $field = Text::make('title')->nullable();

    expect($field->buildRules())->toContain('nullable');
});

it('default rule is sometimes', function () {
    $field = Text::make('title');

    expect($field->buildRules())->toContain('sometimes');
});

it('rules() appends extra rules', function () {
    $field = Text::make('title')->rules(['email', 'max:255']);

    expect($field->buildRules())->toContain('email')
        ->and($field->buildRules())->toContain('max:255');
});

// ---------------------------------------------------------------------------
// Field base — readonly prevents fill
// ---------------------------------------------------------------------------

it('readonly field cannot be filled', function () {
    $model = new FieldTestModel(['title' => 'original']);
    $field = Text::make('title')->readonly();
    $field->fill($model, 'changed');

    expect($model->getAttribute('title'))->toBe('original');
});

// ---------------------------------------------------------------------------
// Field base — resolveUsing / fillUsing hooks
// ---------------------------------------------------------------------------

it('resolveUsing callback overrides default resolution', function () {
    $model = new FieldTestModel(['title' => 'hello']);
    $field = Text::make('title')->resolveUsing(fn ($v) => strtoupper($v));

    expect($field->resolve($model))->toBe('HELLO');
});

it('fillUsing callback overrides default fill', function () {
    $model = new FieldTestModel;
    $called = false;
    $field = Text::make('title')->fillUsing(function ($m, $v) use (&$called) {
        $called = true;
        $m->setAttribute('title', strtolower($v));
    });

    $field->fill($model, 'UPPER');

    expect($called)->toBeTrue()
        ->and($model->getAttribute('title'))->toBe('upper');
});

// ---------------------------------------------------------------------------
// Field base — sortable / searchable
// ---------------------------------------------------------------------------

it('sortable flag defaults to false', function () {
    expect(Text::make('title')->isSortable())->toBeFalse();
});

it('sortable() marks field as sortable', function () {
    expect(Text::make('title')->sortable()->isSortable())->toBeTrue();
});

it('searchable flag defaults to false', function () {
    expect(Text::make('title')->isSearchable())->toBeFalse();
});

it('searchable() marks field as searchable', function () {
    expect(Text::make('title')->searchable()->isSearchable())->toBeTrue();
});

// ---------------------------------------------------------------------------
// DateTime
// ---------------------------------------------------------------------------

it('DateTime::make creates a datetime field', function () {
    $field = Martis\Fields\DateTime::make('created_at');

    expect($field->attribute())->toBe('created_at')
        ->and($field->type())->toBe('datetime');
});

it('DateTime inherits date resolution', function () {
    $date = new DateTime('2025-06-15 14:30:00');
    $model = new FieldTestModel;
    $model->setAttribute('published_at', $date);
    $field = Martis\Fields\DateTime::make('published_at');

    expect($field->resolve($model))->toBeString();
});

it('DateTime toArray has correct type', function () {
    $field = Martis\Fields\DateTime::make('created_at')->sortable();
    $arr = $field->toArray();

    expect($arr['type'])->toBe('datetime')
        ->and($arr['sortable'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Id
// ---------------------------------------------------------------------------

it('Id::make creates an id field', function () {
    $field = Id::make();

    expect($field->attribute())->toBe('id')
        ->and($field->type())->toBe('id')
        ->and($field->label())->toBe('ID');
});

it('Id defaults to readonly and hidden from forms', function () {
    $field = Id::make();
    $arr = $field->toArray();

    expect($arr['readonly'])->toBeTrue()
        ->and($arr['showOnForms'])->toBeFalse()
        ->and($arr['sortable'])->toBeTrue();
});

it('Id readonly prevents fill', function () {
    $model = new FieldTestModel;
    $model->setAttribute('id', 1);
    $field = Id::make();
    $field->fill($model, 999);

    expect($model->getAttribute('id'))->toBe(1);
});

it('Id accepts custom attribute and label', function () {
    $field = Id::make('uuid', 'UUID');

    expect($field->attribute())->toBe('uuid')
        ->and($field->label())->toBe('UUID');
});

// ---------------------------------------------------------------------------
// Email
// ---------------------------------------------------------------------------

it('Email::make creates an email field', function () {
    $field = Email::make('email');

    expect($field->type())->toBe('email');
});

it('Email adds email validation rule', function () {
    $field = Email::make('email');
    $rules = $field->buildRules();

    expect($rules)->toContain('email');
});

it('Email required adds both required and email rules', function () {
    $field = Email::make('email')->required();
    $rules = $field->buildRules();

    expect($rules)->toContain('required')
        ->and($rules)->toContain('email');
});

it('Email resolves value from model', function () {
    $model = new FieldTestModel;
    $model->setAttribute('email', 'test@example.com');
    $field = Email::make('email');

    expect($field->resolve($model))->toBe('test@example.com');
});

// ---------------------------------------------------------------------------
// Password
// ---------------------------------------------------------------------------

it('Password::make creates a password field', function () {
    $field = Password::make('password');

    expect($field->type())->toBe('password');
});

it('Password is hidden from index and detail by default', function () {
    $field = Password::make('password');
    $arr = $field->toArray();

    expect($arr['showOnIndex'])->toBeFalse()
        ->and($arr['showOnDetail'])->toBeFalse()
        ->and($arr['showOnForms'])->toBeTrue();
});

it('Password resolve always returns null', function () {
    $model = new FieldTestModel;
    $model->setAttribute('password', 'hashed_value');
    $field = Password::make('password');

    expect($field->resolve($model))->toBeNull();
});

it('Password fill does not write when value is empty', function () {
    $model = new FieldTestModel;
    $model->setAttribute('password', 'existing');
    $field = Password::make('password');
    $field->fill($model, '');

    expect($model->getAttribute('password'))->toBe('existing');
});

it('Password fill does not write when value is null', function () {
    $model = new FieldTestModel;
    $model->setAttribute('password', 'existing');
    $field = Password::make('password');
    $field->fill($model, null);

    expect($model->getAttribute('password'))->toBe('existing');
});

// ---------------------------------------------------------------------------
// Hidden
// ---------------------------------------------------------------------------

it('Hidden::make creates a hidden field', function () {
    $field = Hidden::make('tenant_id');

    expect($field->attribute())->toBe('tenant_id')
        ->and($field->type())->toBe('hidden');
});

it('Hidden is not shown on index or detail', function () {
    $field = Hidden::make('tenant_id');
    $arr = $field->toArray();

    expect($arr['showOnIndex'])->toBeFalse()
        ->and($arr['showOnDetail'])->toBeFalse()
        ->and($arr['showOnForms'])->toBeTrue();
});

it('Hidden resolves and fills like a standard field', function () {
    $model = new FieldTestModel(['status' => 'draft']);
    $field = Hidden::make('status');

    expect($field->resolve($model))->toBe('draft');

    $field->fill($model, 'published');
    expect($model->getAttribute('status'))->toBe('published');
});

it('Hidden label is auto-generated from attribute', function () {
    $field = Hidden::make('tenant_id');

    expect($field->label())->toBe('Tenant Id');
});

// ---------------------------------------------------------------------------
// Heading
// ---------------------------------------------------------------------------

it('Heading::make creates a heading field', function () {
    $field = Heading::make('section', 'Personal Info');

    expect($field->attribute())->toBe('section')
        ->and($field->type())->toBe('heading')
        ->and($field->label())->toBe('Personal Info');
});

it('Heading is hidden from index', function () {
    $field = Heading::make('section', 'Info');
    $arr = $field->toArray();

    expect($arr['showOnIndex'])->toBeFalse()
        ->and($arr['showOnDetail'])->toBeTrue()
        ->and($arr['showOnForms'])->toBeTrue();
});

it('Heading content appears in toArray', function () {
    $field = Heading::make('section', 'Settings')
        ->content('Configure your preferences');
    $arr = $field->toArray();

    expect($arr['content'])->toBe('Configure your preferences');
});

it('Heading resolve always returns null', function () {
    $model = new FieldTestModel(['title' => 'test']);
    $field = Heading::make('section', 'Header');

    expect($field->resolve($model))->toBeNull();
});

it('Heading fill is a no-op', function () {
    $model = new FieldTestModel(['title' => 'original']);
    $field = Heading::make('title', 'Header');
    $field->fill($model, 'changed');

    expect($model->getAttribute('title'))->toBe('original');
});

// ---------------------------------------------------------------------------
// withMeta — all fields
// ---------------------------------------------------------------------------

it('withMeta merges arbitrary metadata into toArray', function () {
    $field = Text::make('title')->withMeta(['highlight' => true, 'maxWidth' => 300]);
    $arr = $field->toArray();

    expect($arr['highlight'])->toBeTrue()
        ->and($arr['maxWidth'])->toBe(300);
});

it('withMeta is chainable and merges multiple calls', function () {
    $field = Text::make('title')
        ->withMeta(['a' => 1])
        ->withMeta(['b' => 2]);
    $arr = $field->toArray();

    expect($arr['a'])->toBe(1)
        ->and($arr['b'])->toBe(2);
});
