<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\MartisManager;
use Martis\Tests\Fixtures\ConfigCallables\PageTitle;

beforeEach(function () {
    config()->set('martis.brand.name', 'Acme');
    config()->set('martis.brand.page_title', null);
    app(MartisManager::class)->forgetPageTitle();
});

function pageTitleFor(string $path): string
{
    return app(MartisManager::class)->resolvePageTitle(Request::create($path));
}

it('renders a literal page_title as the title', function () {
    config()->set('martis.brand.page_title', 'Acme Back Office');

    expect(pageTitleFor('/martis/resources/users'))->toBe('Acme Back Office');
});

it('never calls a page_title that names a PHP function', function (string $title) {
    config()->set('martis.brand.page_title', $title);

    expect(pageTitleFor('/martis'))->toBe($title);
})->with(['Date', 'Link', 'Mail']);

it('resolves page_title in each callable form', function (mixed $configured, string $expected) {
    config()->set('martis.brand.page_title', $configured);

    expect(pageTitleFor('/martis/resources/users'))->toBe($expected);
})->with([
    'invokable class name' => [PageTitle::class, 'Acme · invokable · martis/resources/users'],
    'static method array' => [[PageTitle::class, 'resolve'], 'static · martis/resources/users'],
    'closure' => [fn (Request $request): string => 'closure · '.$request->path(), 'closure · martis/resources/users'],
]);

it('rejects a page_title that is neither a string nor a callable', function () {
    config()->set('martis.brand.page_title', [PageTitle::class, 'handle']);

    expect(fn () => pageTitleFor('/martis'))->toThrow(
        InvalidArgumentException::class,
        'The [martis.brand.page_title] config value is not a callable: '.PageTitle::class.'::handle() is not a public static method.',
    );
});

it('falls back to the default title when the page_title resolver returns nothing', function () {
    config()->set('martis.brand.page_title', fn (Request $request): string => '');

    expect(pageTitleFor('/martis'))->toBe('Acme — Admin Control');
});

it('lets Martis::pageTitleUsing() win over page_title', function () {
    config()->set('martis.brand.page_title', PageTitle::class);
    app(MartisManager::class)->pageTitleUsing(fn (Request $request): string => 'runtime · '.$request->path());

    expect(pageTitleFor('/martis'))->toBe('runtime · martis');
});
