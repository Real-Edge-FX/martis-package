<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Resource;
use Martis\SearchResolver;

/*
 * SearchResolver qualifies the searched columns with the model's table only
 * for a relationship panel (`qualifyColumns: true`), whose relation can join
 * a table with the same column (a hasManyThrough's intermediate, a pivot).
 * The resource index keeps the columns as written: its indexQuery() may join
 * a table whose column a searchable field reads.
 */

class SCQAuthorModel extends Model
{
    protected $table = 'scq_authors';

    protected $guarded = [];

    public $timestamps = false;
}

class SCQBookModel extends Model
{
    protected $table = 'scq_books';

    protected $guarded = [];

    public $timestamps = false;
}

/** Searches a column only the joined author table has. */
class SCQPenNameResource extends Resource
{
    public static function model(): string
    {
        return SCQBookModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('pen_name', 'Pen name')->searchable()];
    }
}

/** Searches a column both tables have. */
class SCQTitleResource extends Resource
{
    public static function model(): string
    {
        return SCQBookModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('title', 'Title')->searchable()];
    }
}

beforeEach(function () {
    Schema::create('scq_authors', function ($table) {
        $table->id();
        $table->string('pen_name');
        $table->string('title');
    });
    Schema::create('scq_books', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('author_id');
    });

    $author = SCQAuthorModel::create(['pen_name' => 'Ferrante', 'title' => 'Ms']);
    SCQBookModel::create(['title' => 'My Brilliant Friend', 'author_id' => $author->id]);
});

afterEach(function () {
    Schema::dropIfExists('scq_books');
    Schema::dropIfExists('scq_authors');
});

it('keeps the resource index search columns as written, so a joined column stays searchable', function () {
    $query = SCQBookModel::query()
        ->join('scq_authors', 'scq_authors.id', '=', 'scq_books.author_id')
        ->select('scq_books.*', 'scq_authors.pen_name');

    SearchResolver::apply(Request::create('/'), $query, SCQPenNameResource::class, 'Ferrante');

    expect($query->get()->pluck('title')->all())->toBe(['My Brilliant Friend']);
});

it('qualifies the searched columns for a relationship panel, where a joined table has the same column', function () {
    $query = SCQBookModel::query()
        ->join('scq_authors', 'scq_authors.id', '=', 'scq_books.author_id')
        ->select('scq_books.*');

    SearchResolver::apply(Request::create('/'), $query, SCQTitleResource::class, 'Brilliant', qualifyColumns: true);

    expect($query->toSql())->toContain('"scq_books"."title" like');
    expect($query->get()->pluck('title')->all())->toBe(['My Brilliant Friend']);
});
