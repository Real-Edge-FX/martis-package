<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Fields\Tag;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * `Tag::showCreateRelationButton()` adds an inline create button to the tag
 * picker. Like the `BelongsTo` one, the button follows the related resource's
 * policy (the schema serialises it false when the user may not create a
 * record there), and an inline-create form never offers another inline
 * create, so the schema of that form turns the button off for its Tag fields
 * as it does for BelongsTo and MorphTo.
 */

class TagInlineCreateLabelModel extends Model
{
    protected $table = 'martis_test_tag_labels';
}

class TagInlineCreateArticleModel extends Model
{
    protected $table = 'martis_test_tag_articles';
}

class TagInlineCreateLabelResource extends Resource
{
    public static bool $creatable = true;

    public static function model(): string
    {
        return TagInlineCreateLabelModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }

    public function authorizedToCreate(Request $request): bool
    {
        return static::$creatable;
    }
}

class TagInlineCreateArticleResource extends Resource
{
    public static function model(): string
    {
        return TagInlineCreateArticleModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            Tag::make('labels')->relatedResource('tag-inline-create-label-models')->showCreateRelationButton(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    TagInlineCreateLabelResource::$creatable = true;

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(TagInlineCreateLabelResource::class);
    $registry->register(TagInlineCreateArticleResource::class);
});

function tagInlineCreateField(): Tag
{
    return Tag::make('labels')->relatedResource('tag-inline-create-label-models')->showCreateRelationButton();
}

it('serialises the inline create button when the user may create the related resource', function () {
    expect(tagInlineCreateField()->toArray()['showCreateRelationButton'] ?? false)->toBeTrue();
});

it('serialises no inline create button when the user may not create the related resource', function () {
    TagInlineCreateLabelResource::$creatable = false;

    expect(tagInlineCreateField()->isShowCreateRelationButton())->toBeFalse()
        ->and(tagInlineCreateField()->toArray()['showCreateRelationButton'] ?? false)->toBeFalse();
});

it('turns the inline create button off for the Tag fields of an inline-create form', function () {
    $response = $this->getJson('/martis/api/resources/tag-inline-create-article-models/inline-create-schema');

    $response->assertOk();
    $labels = collect($response->json('data.fields'))->firstWhere('attribute', 'labels');
    expect($labels)->not->toBeNull()
        ->and($labels['showCreateRelationButton'] ?? false)->toBeFalse();
});
