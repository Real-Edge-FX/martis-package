<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Concerns\ProvidesToolFields;
use Martis\Contracts\ProvidesFields;
use Martis\Facades\Martis;
use Martis\Fields\Slug;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Tools\Tool;

class FieldsTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Fields Tool', uriKey: 'fields-tool');
    }

    public function fields(Request $request): array
    {
        return [Slug::make('Slug'), Text::make('Title')];
    }
}

class NoFieldsTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'No Fields Tool', uriKey: 'no-fields-tool');
    }
}

class UnauthorizedFieldsTool extends Tool implements ProvidesFields
{
    use ProvidesToolFields;

    public function __construct()
    {
        parent::__construct(name: 'Unauthorized Fields Tool', uriKey: 'unauthorized-fields-tool');
        $this->canSee(fn (Request $r) => false);
    }

    public function fields(Request $request): array
    {
        return [Slug::make('Slug'), Text::make('Title')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);
    Martis::tools([]); // reset
});

afterEach(function () {
    Martis::tools([]);
});

it('GET /martis/api/tools/{uriKey}/fields returns the serialized field definitions', function () {
    Martis::tools([new FieldsTool]);

    $response = $this->getJson('/martis/api/tools/fields-tool/fields');

    $response->assertStatus(200);

    // Round-trip the expected shape through JSON encode/decode too — some
    // field definitions (e.g. Slug's conditional `sometimes` rule) carry a
    // Closure in their raw toArray() that is dropped on JSON serialization,
    // so comparing against the raw array would be an apples-to-oranges
    // mismatch against what the endpoint actually returns over HTTP.
    $expected = json_decode(json_encode(array_map(
        fn ($f) => $f->toArray(),
        (new FieldsTool)->fields(request()),
    )), true);

    expect($response->json('data.fields'))->toEqual($expected);
});

it('GET /martis/api/tools/{uriKey}/fields returns an empty list when the Tool does not implement ProvidesFields', function () {
    Martis::tools([new NoFieldsTool]);

    $response = $this->getJson('/martis/api/tools/no-fields-tool/fields');

    $response->assertStatus(200);
    expect($response->json('data.fields'))->toBe([]);
});

it('GET /martis/api/tools/{uriKey}/fields returns 404 (not 403/200) when canSee() denies the user', function () {
    Martis::tools([new UnauthorizedFieldsTool]);

    // Indistinguishable from "not found" by design — an unauthorised
    // user must not be able to enumerate a tool's field definitions.
    $response = $this->getJson('/martis/api/tools/unauthorized-fields-tool/fields');

    $response->assertStatus(404);
    expect($response->json('data.fields'))->toBeNull();
});

it('GET /martis/api/tools/{uriKey}/fields returns 404 for an unknown tool key', function () {
    Martis::tools([new FieldsTool]);

    $response = $this->getJson('/martis/api/tools/nope/fields');

    $response->assertStatus(404);
});
