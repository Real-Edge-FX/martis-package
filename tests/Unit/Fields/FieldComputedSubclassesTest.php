<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Martis\Fields\Code;
use Martis\Fields\File;
use Martis\Fields\Gravatar;
use Martis\Fields\Image;
use Martis\Fields\KeyValue;
use Martis\Fields\MultiSelect;
use Martis\Fields\Sparkline;
use Martis\Tests\TestCase;

// File/Image resolve through Storage::disk(), which needs the container.
uses(TestCase::class);

/**
 * `mode()` is a plain method returning a non-relation, so any direct
 * `getAttribute('mode')` left inside a subclass resolve() throws
 * LogicException. Every test below fails loudly if a subclass bypasses
 * the resolveAttribute() seam.
 */
class ComputedSubclassTestModel extends Model
{
    protected $table = 'channels';

    protected $fillable = ['name'];

    public $timestamps = false;

    public function mode(): string
    {
        return 'feed';
    }
}

beforeEach(function () {
    Storage::fake('public');
});

it('KeyValue decodes the computed value into rows', function () {
    $field = KeyValue::make('mode')->computed(fn () => ['transport' => 'feed']);

    expect($field->resolve(new ComputedSubclassTestModel))
        ->toBe([['key' => 'transport', 'value' => 'feed']]);
});

it('MultiSelect decodes the computed value into a list', function () {
    $field = MultiSelect::make('mode')->computed(fn () => ['push', 'feed']);

    expect($field->resolve(new ComputedSubclassTestModel))->toBe(['push', 'feed']);
});

it('Sparkline uses the computed value as its series', function () {
    $field = Sparkline::make('mode')->computed(fn () => [1, 2, 3]);

    expect($field->resolve(new ComputedSubclassTestModel))->toBe([1, 2, 3]);
});

it('Sparkline still prefers chartData over the computed value', function () {
    $field = Sparkline::make('mode')->data([9, 9])->computed(fn () => [1, 2, 3]);

    expect($field->resolve(new ComputedSubclassTestModel))->toBe([9, 9]);
});

it('Gravatar builds the avatar URL from the computed email', function () {
    $field = Gravatar::make('mode')->computed(fn () => 'someone@example.com');

    expect($field->resolve(new ComputedSubclassTestModel))
        ->toBe(Gravatar::gravatarUrl('someone@example.com'));
});

it('File resolves the computed path in single mode', function () {
    $field = File::make('mode')->computed(fn () => 'docs/report.pdf');

    $resolved = $field->resolve(new ComputedSubclassTestModel);

    expect($resolved['path'])->toBe('docs/report.pdf');
    expect($resolved['name'])->toBe('report.pdf');
    expect($resolved['url'])->toContain('docs/report.pdf');
});

it('File resolves the computed paths in multiple mode', function () {
    $field = File::make('mode')->multiple()->computed(fn () => ['docs/a.pdf', 'docs/b.pdf']);

    $resolved = $field->resolve(new ComputedSubclassTestModel);

    expect(array_column($resolved, 'path'))->toBe(['docs/a.pdf', 'docs/b.pdf']);
});

it('Image resolves the computed path in single mode', function () {
    $field = Image::make('mode')->computed(fn () => 'uploads/test.png');

    $resolved = $field->resolve(new ComputedSubclassTestModel);

    expect($resolved['path'])->toBe('uploads/test.png');
    expect($resolved['thumbnailUrl'])->toContain('uploads/test.png');
});

it('Image resolves the computed paths in multiple mode', function () {
    $field = Image::make('mode')->multiple()->computed(fn () => ['uploads/a.png', 'uploads/b.png']);

    $resolved = $field->resolve(new ComputedSubclassTestModel);

    expect(array_column($resolved, 'path'))->toBe(['uploads/a.png', 'uploads/b.png']);
});

it('hands null to resolveUsing() on every seam subclass when computed() has no callback', function () {
    $model = new ComputedSubclassTestModel;
    $fields = [
        KeyValue::make('mode'),
        MultiSelect::make('mode'),
        Sparkline::make('mode'),
        Gravatar::make('mode'),
        File::make('mode'),
        Image::make('mode'),
    ];

    foreach ($fields as $field) {
        $seen = 'sentinel';
        $field->computed()->resolveUsing(function ($value) use (&$seen) {
            $seen = $value;

            return 'ok';
        });

        expect($field->resolve($model))->toBe('ok', $field::class);
        expect($seen)->toBeNull($field::class);
    }
});

it('resolves a computed field to an empty payload when the callback returns null', function () {
    $model = new ComputedSubclassTestModel;

    expect(KeyValue::make('mode')->computed()->resolve($model))->toBe([]);
    expect(MultiSelect::make('mode')->computed()->resolve($model))->toBe([]);
    expect(Sparkline::make('mode')->computed()->resolve($model))->toBe([]);
    expect(Gravatar::make('mode')->computed()->resolve($model))->toBeNull();
    expect(File::make('mode')->computed()->resolve($model))->toBeNull();
    expect(File::make('mode')->multiple()->computed()->resolve($model))->toBe([]);
    expect(Image::make('mode')->computed()->resolve($model))->toBeNull();
    expect(Image::make('mode')->multiple()->computed()->resolve($model))->toBe([]);
});

it('keeps fill() a no-op on every subclass that re-implements it, even when shown on forms', function () {
    $model = new ComputedSubclassTestModel;
    $fields = [
        KeyValue::make('mode')->computed()->showOnForms(),
        MultiSelect::make('mode')->computed()->showOnForms(),
        Sparkline::make('mode')->computed()->showOnForms(),
        Gravatar::make('mode')->fromUrl()->computed()->showOnForms(),
        File::make('mode')->computed()->showOnForms(),
        Image::make('mode')->computed()->showOnForms(),
        Code::make('mode')->computed()->showOnForms(),
    ];

    foreach ($fields as $field) {
        $field->fill($model, 'docs/report.pdf');

        expect($model->getAttributes())->toBe([], $field::class);
    }
});
