<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A BelongsToMany / MorphToMany pivot row needs the record's key, which a
 * create form does not have yet. Like Nova, which drops its ListableFields
 * from every creation form, the schema keeps both fields off the create
 * page, the create drawer and the inline-create modal, whatever visibility
 * the resource asks for; the update form keeps them. Before v1.38.0
 * showOnCreating() brought them onto the create forms, where the panel
 * asked /api/resources/{resource}//belongs-to-many/... (404) on the create
 * page and read, and attached to, the page's record in a create drawer or
 * modal opened over another record.
 */

class ManyToManyCreateFormsModel extends Model
{
    protected $table = 'm2m_create_forms_projects';
}

class ManyToManyCreateFormsResource extends Resource
{
    public static function model(): string
    {
        return ManyToManyCreateFormsModel::class;
    }

    public static function uriKey(): string
    {
        return 'm2m-create-forms-projects';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->required(),
            BelongsToMany::make('Members', 'members')->showOnCreating(),
            Section::make('Labels', [
                MorphToMany::make('Tags', 'tags')->onlyOnForms(),
            ]),
        ];
    }
}

beforeEach(function () {
    $registry = app(ResourceRegistry::class);
    $registry->register(ManyToManyCreateFormsResource::class);

    $this->withoutMiddleware(MartisAuthenticate::class);
});

/**
 * Attribute of every field in a serialized field list, layout containers
 * opened (a section's fields, a tab's fields).
 *
 * @param  list<array<string, mixed>>  $items
 * @return list<string>
 */
function manyToManyFormAttributes(array $items): array
{
    $attributes = [];
    foreach ($items as $item) {
        if (isset($item['attribute'])) {
            $attributes[] = $item['attribute'];

            continue;
        }
        $children = $item['fields'] ?? array_merge([], ...array_column($item['tabs'] ?? [], 'fields'));
        $attributes = [...$attributes, ...manyToManyFormAttributes($children)];
    }

    return $attributes;
}

it('leaves BelongsToMany and MorphToMany off the create forms of the schema', function () {
    $data = $this->getJson('/martis/api/resources/m2m-create-forms-projects/schema')
        ->assertOk()
        ->json('data');

    expect(manyToManyFormAttributes($data['fieldsForCreate']))->toBe(['name'])
        ->and(manyToManyFormAttributes($data['fieldsForInlineCreate']))->toBe(['name']);
});

it('keeps BelongsToMany and MorphToMany on the update form of the schema', function () {
    $data = $this->getJson('/martis/api/resources/m2m-create-forms-projects/schema')
        ->assertOk()
        ->json('data');

    expect(manyToManyFormAttributes($data['fieldsForUpdate']))->toContain('members', 'tags');
});

it('leaves BelongsToMany and MorphToMany off the inline-create modal', function () {
    $fields = $this->getJson('/martis/api/resources/m2m-create-forms-projects/inline-create-schema')
        ->assertOk()
        ->json('data.fields');

    expect(array_column($fields, 'attribute'))->toBe(['name']);
});
