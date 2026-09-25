<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphTo;
use Martis\Fields\MorphToMany;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * The inline create endpoints need the related resource's viewAny as well
 * as create (v2.0), so a relation picker offers its create button only when
 * both allow it: otherwise the button opened a form that answers 403.
 */

class ICVLabelModel extends Model
{
    protected $table = 'icv_labels';
}

class ICVLabelResource extends Resource
{
    public static bool $viewAny = true;

    public static function model(): string
    {
        return ICVLabelModel::class;
    }

    public static function uriKey(): string
    {
        return 'icv-labels';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function authorizedToViewAny(Request $request): bool
    {
        return static::$viewAny;
    }

    public function authorizedToCreate(Request $request): bool
    {
        return true;
    }
}

beforeEach(function () {
    ICVLabelResource::$viewAny = true;
    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ICVLabelResource::class);
});

afterEach(function () {
    app(ResourceRegistry::class)->flush();
});

/** The first `showCreateRelationButton` in a serialised field, wherever it sits. */
function icvCreateButton(array $serialised): ?bool
{
    $found = null;
    array_walk_recursive($serialised, function ($value, $key) use (&$found) {
        if ($key === 'showCreateRelationButton' && $found === null) {
            $found = (bool) $value;
        }
    });

    return $found;
}

dataset('icv pickers', [
    'BelongsTo' => [fn () => BelongsTo::make('label', 'Label', ICVLabelResource::class)->showCreateRelationButton()],
    'Tag' => [fn () => Tag::make('labels')->relatedResource('icv-labels')->showCreateRelationButton()],
    'BelongsToMany' => [fn () => BelongsToMany::make('Labels', 'labels')->relatedResource('icv-labels')->showCreateRelationButton()],
    'MorphToMany' => [fn () => MorphToMany::make('Labels', 'labels')->relatedResource('icv-labels')->showCreateRelationButton()],
    'MorphTo' => [fn () => MorphTo::make('labelable', 'Labelable')->types([ICVLabelResource::class])->showCreateRelationButton()],
]);

it('offers the inline create button when the related resource allows viewAny and create', function (Closure $field) {
    expect(icvCreateButton($field()->toArray()))->toBeTrue();
})->with('icv pickers');

it('offers no inline create button when the related resource denies viewAny', function (Closure $field) {
    ICVLabelResource::$viewAny = false;

    expect(icvCreateButton($field()->toArray()))->toBeFalse();
})->with('icv pickers');

it('marks a MorphTo type that denies viewAny as not creatable', function () {
    ICVLabelResource::$viewAny = false;

    $types = MorphTo::make('labelable', 'Labelable')->types([ICVLabelResource::class])->showCreateRelationButton()->toArray();
    $flags = [];
    array_walk_recursive($types, function ($value, $key) use (&$flags) {
        if ($key === 'authorizedToCreate') {
            $flags[] = $value;
        }
    });

    expect($flags)->toBe([false]);
});
