<?php

use App\Martis\Filters\ScaffoldFlagsFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Martis\Stubs\StubResolver;

// The `martis:filter --boolean` scaffold used to loop over the submitted keys
// and use each as a column name, so every consumer that kept the generated
// body filtered on any column a request named (F073). The generated filter
// whitelists the keys against its own options().

class ScaffoldFlagModel extends Model
{
    protected $table = 'scaffold_flags';

    public $timestamps = false;
}

it('generates a boolean filter that only filters on the columns its options declare', function () {
    $path = app_path('Martis/Filters/ScaffoldFlagsFilter.php');
    (new Filesystem)->ensureDirectoryExists(app_path('Martis/Filters'));

    try {
        $this->artisan('martis:filter', ['name' => 'ScaffoldFlagsFilter', '--boolean' => true])->assertSuccessful();
        expect(file_exists($path))->toBeTrue();

        require_once $path;

        $filter = new class('Flags') extends ScaffoldFlagsFilter
        {
            public function options(Request $request): array
            {
                return ['Flagged' => 'is_flagged', 'Archived' => 'is_archived'];
            }
        };

        $query = ScaffoldFlagModel::query();
        $filter->apply(Request::create('/'), $query, ['is_flagged' => true, 'is_secret' => true, 'settings->flag' => true, 'is_archived' => false]);

        expect($query->toSql())->toContain('"is_flagged" = ?')
            ->and($query->toSql())->not->toContain('is_secret')
            ->and($query->toSql())->not->toContain('settings')
            ->and($query->toSql())->not->toContain('is_archived');

        // A scaffold with the empty options() it ships with filters on nothing.
        $untouched = ScaffoldFlagModel::query();
        (new ScaffoldFlagsFilter('Flags'))->apply(Request::create('/'), $untouched, ['is_secret' => true]);

        expect($untouched->toSql())->not->toContain('where');
    } finally {
        (new Filesystem)->delete($path);
    }
});

it('ships a boolean stub that does not use a request key as a column unchecked', function () {
    $stub = file_get_contents(StubResolver::path('filter.boolean.stub'));

    expect($stub)->toContain('in_array($column, $allowed, true)')
        ->and($stub)->toContain('array_values($this->options($request))');
});
