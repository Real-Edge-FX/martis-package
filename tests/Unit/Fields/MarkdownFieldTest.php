<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\MarkdownPreset;
use Martis\FieldContext;
use Martis\Fields\Markdown;

class MarkdownTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['description_md'];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Construction
// ---------------------------------------------------------------------------

it('Markdown::make creates a markdown field', function () {
    $field = Markdown::make('description_md');

    expect($field->attribute())->toBe('description_md')
        ->and($field->label())->toBe('Description Md')
        ->and($field->type())->toBe('markdown');
});

it('Markdown is hidden from index by default', function () {
    $field = Markdown::make('description_md');

    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
});

// ---------------------------------------------------------------------------
// API configuration — alwaysShow()
// ---------------------------------------------------------------------------

it('Markdown alwaysShow() sets flag', function () {
    $field = Markdown::make('description_md')->alwaysShow();

    expect($field->isAlwaysShow())->toBeTrue()
        ->and($field->toArray()['alwaysShow'])->toBeTrue();
});

it('Markdown defaults to not always show', function () {
    $field = Markdown::make('description_md');

    expect($field->isAlwaysShow())->toBeFalse();
    // alwaysShow=false should be filtered out
    expect($field->toArray())->not->toHaveKey('alwaysShow');
});

// ---------------------------------------------------------------------------
// API configuration — preset()
// ---------------------------------------------------------------------------

it('Markdown preset() sets rendering preset', function () {
    $field = Markdown::make('description_md')->preset(MarkdownPreset::Commonmark);

    expect($field->getPreset())->toBe(MarkdownPreset::Commonmark)
        ->and($field->toArray()['preset'])->toBe('commonmark');
});

it('Markdown defaults to "default" preset', function () {
    $field = Markdown::make('description_md');

    expect($field->getPreset())->toBe(MarkdownPreset::Default);
});

it('Markdown preset("zero") is accepted', function () {
    $field = Markdown::make('description_md')->preset(MarkdownPreset::Zero);

    expect($field->getPreset())->toBe(MarkdownPreset::Zero);
});

// ---------------------------------------------------------------------------
// API configuration — withFiles()
// ---------------------------------------------------------------------------

it('Markdown withFiles() sets disk', function () {
    $field = Markdown::make('description_md')->withFiles('public');

    expect($field->getWithFilesDisk())->toBe('public')
        ->and($field->toArray()['withFiles'])->toBe('public');
});

it('Markdown without withFiles does not include it', function () {
    $field = Markdown::make('description_md');

    expect($field->getWithFilesDisk())->toBeNull()
        ->and($field->toArray())->not->toHaveKey('withFiles');
});

// ---------------------------------------------------------------------------
// Resolve — stores raw Markdown
// ---------------------------------------------------------------------------

it('Markdown resolves raw Markdown from model', function () {
    $md = "# Hello\n\nThis is **bold**.";
    $model = new MarkdownTestModel(['description_md' => $md]);
    $field = Markdown::make('description_md');

    expect($field->resolve($model))->toBe($md);
});

it('Markdown resolves null as null', function () {
    $model = new MarkdownTestModel(['description_md' => null]);
    $field = Markdown::make('description_md');

    expect($field->resolve($model))->toBeNull();
});

// ---------------------------------------------------------------------------
// Fill — stores raw Markdown
// ---------------------------------------------------------------------------

it('Markdown fill() stores raw Markdown string', function () {
    $model = new MarkdownTestModel;
    $field = Markdown::make('description_md');
    $md = "# Title\n\n- item 1\n- item 2";

    $field->fill($model, $md);

    expect($model->getAttribute('description_md'))->toBe($md);
});

it('Markdown fill() does nothing when readonly', function () {
    $model = new MarkdownTestModel(['description_md' => 'original']);
    $field = Markdown::make('description_md')->readonly();

    $field->fill($model, 'changed');

    expect($model->getAttribute('description_md'))->toBe('original');
});

it('Markdown fill() stores null', function () {
    $model = new MarkdownTestModel;
    $field = Markdown::make('description_md');

    $field->fill($model, null);

    expect($model->getAttribute('description_md'))->toBeNull();
});

// ---------------------------------------------------------------------------
// toArray
// ---------------------------------------------------------------------------

it('Markdown toArray contains required keys', function () {
    $field = Markdown::make('description_md', 'Description')
        ->alwaysShow()
        ->preset(MarkdownPreset::Commonmark)
        ->withFiles('public');

    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'label', 'type', 'alwaysShow', 'preset', 'withFiles'])
        ->and($arr['type'])->toBe('markdown')
        ->and($arr['alwaysShow'])->toBeTrue()
        ->and($arr['preset'])->toBe('commonmark')
        ->and($arr['withFiles'])->toBe('public');
});

// ---------------------------------------------------------------------------
// Callbacks
// ---------------------------------------------------------------------------

it('Markdown respects resolveUsing callback', function () {
    $model = new MarkdownTestModel(['description_md' => '# Hello']);
    $field = Markdown::make('description_md')
        ->resolveUsing(fn ($value) => $value."\n\nAppended.");

    expect($field->resolve($model))->toBe("# Hello\n\nAppended.");
});
