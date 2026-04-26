<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Contracts\FieldContract;
use Martis\Contracts\OverrideContract;
use Martis\FieldContext;
use Martis\Resource;
use Martis\Tests\TestCase;

uses(TestCase::class)->afterEach(function () {
    Resource::flushPolicyCache();
});

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

    public function nullable(bool|Closure $value = true): static
    {
        return $this;
    }

    public function readonly(bool|Closure $value = true): static
    {
        return $this;
    }

    public function required(bool|Closure $value = true): static
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

    public function hideWhenCreating(): static
    {
        return $this;
    }

    public function hideWhenUpdating(): static
    {
        return $this;
    }

    public function showOnCreating(): static
    {
        return $this;
    }

    public function showOnUpdating(): static
    {
        return $this;
    }

    public function onlyOnIndex(): static
    {
        return $this;
    }

    public function onlyOnDetail(): static
    {
        return $this;
    }

    public function onlyOnForms(): static
    {
        return $this;
    }

    public function exceptOnForms(): static
    {
        return $this;
    }

    public function isVisibleForContext(FieldContext $context): bool
    {
        return match ($context) {
            index => $this->onIndex,
            detail, preview => $this->onDetail,
            create, update, inline - create => $this->onForms,
            default => true,
        };
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

    public function placeholder(string|Closure $text): static
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

    public function rules(array|Closure $rules): static
    {
        return $this;
    }

    public function dependsOn(array $fields, ?Closure $callback = null): static
    {
        return $this;
    }

    public function dependentFields(): array
    {
        return [];
    }

    public function isDependent(): bool
    {
        return false;
    }

    public function syncDependent(array $formData, Request $request): static
    {
        return $this;
    }

    public function buildRules(?string $context = null): array
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

    public function displayUsing(callable|array $callback): static
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

    public function overrideCreate(OverrideContract $override): static
    {
        return $this;
    }

    public function overrideUpdate(OverrideContract $override): static
    {
        return $this;
    }

    public function overrideIndex(OverrideContract $override): static
    {
        return $this;
    }

    public function overrideDetail(OverrideContract $override): static
    {
        return $this;
    }

    public function getOverrideForContext(FieldContext $context): ?OverrideContract
    {
        return null;
    }

    /** @param array<string, mixed> $meta */
    public function withMeta(array $meta): static
    {
        return $this;
    }

    public function colSpan(int $cols): static
    {
        return $this;
    }

    public function colSpanMd(int $cols): static
    {
        return $this;
    }

    public function colSpanLg(int $cols): static
    {
        return $this;
    }

    public function canSee(callable $callback): static
    {
        return $this;
    }

    public function canSeeWhen(string $ability, mixed ...$arguments): static
    {
        return $this;
    }

    public function isAuthorizedToSee(Request $request): bool
    {
        return true;
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
// Sticky Views (Task 15)
// ---------------------------------------------------------------------------

it('stickyView returns true by default', function () {
    expect(SimpleResource::stickyView())->toBeTrue();
});

it('stickyView returns false when global config disables the feature', function () {
    config()->set('martis.sticky_views.enabled', false);
    expect(SimpleResource::stickyView())->toBeFalse();
    config()->set('martis.sticky_views.enabled', true);
});

it('stickyView returns false when the resource opts out via $stickyView = false', function () {
    $cls = new class extends Martis\Resource
    {
        protected static bool $stickyView = false;

        public static function model(): string
        {
            return User::class;
        }

        public function fields(Request $request): array
        {
            return [];
        }
    };

    expect($cls::stickyView())->toBeFalse();
});
// ---------------------------------------------------------------------------
// Context-aware field resolution — all 7 cenarios
//
// Cenario 1: only fields() defined → all contexts fall back to fields()
// Cenario 2: fieldsForCreate() + fields() defined
// Cenario 3: fieldsForUpdate() + fields() defined
// Cenario 4: fieldsForInlineCreate() + fieldsForCreate() + fields()
// Cenario 5: fieldsForIndex() + fieldsForDetail() + fields()
// Cenario 6: fieldsForPreview() + fields()
// Cenario 7: all context methods overridden
// ---------------------------------------------------------------------------

// Cenario 1 — only fields() implemented; all contexts use it as fallback
it('[C1] fieldsForIndex falls back to fields() when not overridden', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $attrs = array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($request));
    expect($attrs)->toContain('id', 'name', 'email', 'secret');
});

it('[C1] fieldsForDetail falls back to fields() when not overridden', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $attrs = array_map(fn ($f) => $f->attribute(), $resource->fieldsForDetail($request));
    expect($attrs)->toContain('id', 'name', 'email', 'secret');
});

it('[C1] fieldsForCreate falls back to fields() when not overridden', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $attrs = array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($request));
    expect($attrs)->toContain('id', 'name', 'email', 'secret');
});

it('[C1] fieldsForUpdate falls back to fields() when not overridden', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $attrs = array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($request));
    expect($attrs)->toContain('id', 'name', 'email', 'secret');
});

it('[C1] fieldsForInlineCreate falls back to fields() when not overridden', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $attrs = array_map(fn ($f) => $f->attribute(), $resource->fieldsForInlineCreate($request));
    expect($attrs)->toContain('id', 'name', 'email', 'secret');
});

it('[C1] fieldsForPreview falls back to fields() when not overridden', function () {
    $resource = new SimpleResource;
    $request = Request::create('/');
    $attrs = array_map(fn ($f) => $f->attribute(), $resource->fieldsForPreview($request));
    expect($attrs)->toContain('id', 'name', 'email', 'secret');
});

// Cenario 2 — fieldsForCreate() overridden; inline-create uses fieldsForCreate();
// other contexts fall back to fields()
it('[C2] fieldsForCreate uses override; inline-create inherits it; others use fields()', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForCreate(Request $request): array
        {
            return [new StubField('create_only')];
        }
    };
    $req = Request::create('/');
    // create uses override
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['create_only']);
    // inline-create inherits from fieldsForCreate()
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForInlineCreate($req)))->toBe(['create_only']);
    // other contexts fall back to fields()
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForDetail($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForPreview($req)))->toBe(['base']);
});

// Cenario 3 — fieldsForUpdate() overridden; others fall back to fields()
it('[C3] fieldsForUpdate uses override; other contexts use fields()', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForUpdate(Request $request): array
        {
            return [new StubField('update_only')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($req)))->toBe(['update_only']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForInlineCreate($req)))->toBe(['base']);
});

// Cenario 4 — fieldsForInlineCreate + fieldsForCreate + fields() all defined
it('[C4] fieldsForInlineCreate takes precedence over fieldsForCreate in inline context', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForCreate(Request $request): array
        {
            return [new StubField('create_only')];
        }

        public function fieldsForInlineCreate(Request $request): array
        {
            return [new StubField('inline_create_only')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['create_only']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForInlineCreate($req)))->toBe(['inline_create_only']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($req)))->toBe(['base']);
});

// Cenario 5 — fieldsForIndex + fieldsForDetail overridden; others fall back to fields()
it('[C5] fieldsForIndex and fieldsForDetail use overrides; create/update/etc use fields()', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForIndex(Request $request): array
        {
            return [new StubField('index_only')];
        }

        public function fieldsForDetail(Request $request): array
        {
            return [new StubField('detail_only')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($req)))->toBe(['index_only']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForDetail($req)))->toBe(['detail_only']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForInlineCreate($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForPreview($req)))->toBe(['base']);
});

// Cenario 6 — fieldsForPreview() overridden; others fall back to fields()
it('[C6] fieldsForPreview uses override; other contexts use fields()', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForPreview(Request $request): array
        {
            return [new StubField('preview_only')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForPreview($req)))->toBe(['preview_only']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForDetail($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['base']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($req)))->toBe(['base']);
});

// Cenario 7 — all context methods overridden; each context uses its own fields
it('[C7] when all context methods are overridden each uses its own field set', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForIndex(Request $request): array
        {
            return [new StubField('idx')];
        }

        public function fieldsForDetail(Request $request): array
        {
            return [new StubField('dtl')];
        }

        public function fieldsForCreate(Request $request): array
        {
            return [new StubField('crt')];
        }

        public function fieldsForUpdate(Request $request): array
        {
            return [new StubField('upd')];
        }

        public function fieldsForInlineCreate(Request $request): array
        {
            return [new StubField('inl')];
        }

        public function fieldsForPreview(Request $request): array
        {
            return [new StubField('prv')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForIndex($req)))->toBe(['idx']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForDetail($req)))->toBe(['dtl']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['crt']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($req)))->toBe(['upd']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForInlineCreate($req)))->toBe(['inl']);
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForPreview($req)))->toBe(['prv']);
});

// Cross-context isolation — fieldsForCreate must not leak into fieldsForUpdate and vice versa
it('fieldsForCreate does not leak into fieldsForUpdate', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForCreate(Request $request): array
        {
            return [new StubField('create_only')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForUpdate($req)))->toBe(['base']);
});

it('fieldsForUpdate does not leak into fieldsForCreate', function () {
    $resource = new class extends Resource
    {
        public static function model(): string
        {
            return SimpleModel::class;
        }

        public function fields(Request $request): array
        {
            return [new StubField('base')];
        }

        public function fieldsForUpdate(Request $request): array
        {
            return [new StubField('update_only')];
        }
    };
    $req = Request::create('/');
    expect(array_map(fn ($f) => $f->attribute(), $resource->fieldsForCreate($req)))->toBe(['base']);
});

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
