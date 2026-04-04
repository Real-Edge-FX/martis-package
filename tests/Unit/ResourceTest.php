<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Contracts\FieldContract;
use Martis\Resource;
use Martis\Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class SimpleModelPolicy
{
    public function viewAny(): bool
    {
        return false;
    }

    public function view(): bool
    {
        return true;
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return false;
    }
}

class SimpleModel extends Model
{
    protected $table = 'users';
}

class SoftDeletableModel extends Model
{
    use SoftDeletes;

    protected $table = 'users';
}

/** @phpstan-ignore-next-line */
class StubField implements FieldContract
{
    public function __construct(
        private readonly string $attr,
        private bool $onIndex = true,
        private bool $onDetail = true,
        private bool $onForms = true,
    ) {}

    public function attribute(): string
    {
        return $this->attr;
    }

    public function label(): string
    {
        return ucfirst($this->attr);
    }

    public function type(): string
    {
        return 'text';
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    public function fill(Model $model, mixed $value): void {}

    public function toArray(): array
    {
        return ['attribute' => $this->attr, 'type' => 'text'];
    }

    public function nullable(): static
    {
        return $this;
    }

    public function readonly(): static
    {
        return $this;
    }

    public function required(): static
    {
        return $this;
    }

    public function showOnIndex(): static
    {
        $this->onIndex = true;

        return $this;
    }

    public function hideFromIndex(): static
    {
        $this->onIndex = false;

        return $this;
    }

    public function showOnDetail(): static
    {
        $this->onDetail = true;

        return $this;
    }

    public function hideFromDetail(): static
    {
        $this->onDetail = false;

        return $this;
    }

    public function showOnForms(): static
    {
        $this->onForms = true;

        return $this;
    }

    public function hideFromForms(): static
    {
        $this->onForms = false;

        return $this;
    }

    public function isShownOnIndex(): bool
    {
        return $this->onIndex;
    }

    public function isShownOnDetail(): bool
    {
        return $this->onDetail;
    }

    public function isShownOnForms(): bool
    {
        return $this->onForms;
    }

    public static function make(string $attribute, ?string $label = null): static
    {
        return new static($attribute);
    }

    public function placeholder(string $text): static
    {
        return $this;
    }

    public function sortable(bool $value = true): static
    {
        return $this;
    }

    public function searchable(bool $value = true): static
    {
        return $this;
    }

    public function isSortable(): bool
    {
        return false;
    }

    public function isSearchable(): bool
    {
        return false;
    }

    public function rules(array $rules): static
    {
        return $this;
    }

    public function buildRules(): array
    {
        return [];
    }

    public function resolveUsing(callable $callback): static
    {
        return $this;
    }

    public function fillUsing(callable $callback): static
    {
        return $this;
    }

    public function displayUsing(callable $callback): static
    {
        return $this;
    }

    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        return null;
    }

    public function component(string $key): static
    {
        return $this;
    }

    public function getComponentKey(): ?string
    {
        return null;
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): static
    {
        return $this;
    }
}

class SimpleResource extends Resource
{
    public static function model(): string
    {
        return SimpleModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            new StubField('id', onIndex: true, onDetail: true, onForms: false),
            new StubField('name', onIndex: true, onDetail: true, onForms: true),
            new StubField('email', onIndex: true, onDetail: true, onForms: true),
            new StubField('secret', onIndex: false, onDetail: false, onForms: false),
        ];
    }
}

class SoftDeletableResource extends Resource
{
    public static function model(): string
    {
        return SoftDeletableModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Metadata tests
// ---------------------------------------------------------------------------

it('derives uriKey from model class name', function () {
    expect(SimpleResource::uriKey())->toBe('simple-models');
});

it('derives label (plural) from model class name', function () {
    expect(SimpleResource::label())->toBe('Simple Models');
});

it('derives singularLabel from model class name', function () {
    expect(SimpleResource::singularLabel())->toBe('Simple Model');
});

it('newModel returns a fresh model instance', function () {
    $model = SimpleResource::newModel();
    expect($model)->toBeInstanceOf(SimpleModel::class);
    expect($model->exists)->toBeFalse();
});

it('toArray contains expected metadata keys', function () {
    $resource = new SimpleResource;
    $array = $resource->toArray();

    expect($array)->toHaveKeys(['uriKey', 'label', 'singularLabel', 'softDeletes']);
    expect($array['uriKey'])->toBe('simple-models');
    expect($array['label'])->toBe('Simple Models');
    expect($array['singularLabel'])->toBe('Simple Model');
    expect($array['softDeletes'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Soft deletes
// ---------------------------------------------------------------------------

it('softDeletes returns false when model does not use SoftDeletes', function () {
    expect(SimpleResource::softDeletes())->toBeFalse();
});

it('softDeletes returns true when model uses SoftDeletes', function () {
    expect(SoftDeletableResource::softDeletes())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Context-aware field resolution
// ---------------------------------------------------------------------------

it('fieldsForIndex returns only fields shown on index', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $fields = $resource->fieldsForIndex($request);

    expect($fields)->toHaveCount(3);
    expect(array_map(fn ($f) => $f->attribute(), $fields))->toContain('id', 'name', 'email');
    expect(array_map(fn ($f) => $f->attribute(), $fields))->not->toContain('secret');
});

it('fieldsForDetail returns only fields shown on detail', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $fields = $resource->fieldsForDetail($request);

    expect($fields)->toHaveCount(3);
    expect(array_map(fn ($f) => $f->attribute(), $fields))->not->toContain('secret');
});

it('fieldsForForms returns only fields shown on forms', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $fields = $resource->fieldsForForms($request);

    // id is hidden from forms; secret is hidden from all
    expect($fields)->toHaveCount(2);
    expect(array_map(fn ($f) => $f->attribute(), $fields))->toContain('name', 'email');
    expect(array_map(fn ($f) => $f->attribute(), $fields))->not->toContain('id', 'secret');
});

it('fieldsForIndex returns empty array when all fields are hidden', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('hidden', onIndex: false, onDetail: false, onForms: false)];
        }
    };

    expect($resource->fieldsForIndex(Request::create('/')))->toBe([]);
    expect($resource->fieldsForDetail(Request::create('/')))->toBe([]);
    expect($resource->fieldsForForms(Request::create('/')))->toBe([]);
});

it('fieldsForIndex returns sequential numeric keys', function () {
    $resource = new SimpleResource;
    $fields = $resource->fieldsForIndex(Request::create('/'));

    expect(array_is_list($fields))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Constructor and model accessor
// ---------------------------------------------------------------------------

it('stores model instance passed to constructor', function () {
    $model = new SimpleModel;
    $resource = new SimpleResource($model);

    expect($resource->getModel())->toBe($model);
});

it('getModel returns null when no model given', function () {
    $resource = new SimpleResource;
    expect($resource->getModel())->toBeNull();
});

// ---------------------------------------------------------------------------
// Authorization — permissive when no policy registered
// ---------------------------------------------------------------------------

it('authorization methods return true when no policy is registered', function () {
    $resource = new SimpleResource(new SimpleModel);
    $request = Request::create('/');

    expect($resource->authorizedToViewAny($request))->toBeTrue();
    expect($resource->authorizedToView($request))->toBeTrue();
    expect($resource->authorizedToCreate($request))->toBeTrue();
    expect($resource->authorizedToUpdate($request))->toBeTrue();
    expect($resource->authorizedToDelete($request))->toBeTrue();
});

it('authorization defers to registered policy', function () {
    Gate::policy(SimpleModel::class, SimpleModelPolicy::class);

    $resource = new SimpleResource(new SimpleModel);
    $request = Request::create('/');

    expect($resource->authorizedToViewAny($request))->toBeFalse();
    expect($resource->authorizedToCreate($request))->toBeFalse();
    expect($resource->authorizedToDelete($request))->toBeFalse();
});
