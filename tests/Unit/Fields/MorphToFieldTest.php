<?php

namespace Martis\Tests\Unit\Fields;

use Martis\Enums\ModalSize;
use Martis\Fields\MorphTo;
use PHPUnit\Framework\TestCase;

class MorphToFieldTest extends TestCase
{
    public function test_make_creates_morph_to_field(): void
    {
        $field = MorphTo::make('commentable', 'Commentable');

        $this->assertSame('commentable', $field->attribute());
        $this->assertSame('Commentable', $field->label());
    }

    public function test_type_returns_morph_to(): void
    {
        $field = MorphTo::make('commentable');

        $arr = $field->toArray();
        $this->assertSame('morph_to', $arr['type']);
    }

    public function test_extra_attributes_include_morph_columns(): void
    {
        $field = MorphTo::make('commentable');

        $arr = $field->toArray();
        $this->assertSame('commentable', $arr['relationship']);
        $this->assertSame('commentable_type', $arr['morphTypeColumn']);
        $this->assertSame('commentable_id', $arr['morphIdColumn']);
    }

    public function test_title_attribute_default(): void
    {
        $field = MorphTo::make('commentable');

        $arr = $field->toArray();
        $this->assertSame('name', $arr['titleAttribute']);
    }

    public function test_title_attribute_custom(): void
    {
        $field = MorphTo::make('commentable')->titleAttribute('title');

        $arr = $field->toArray();
        $this->assertSame('title', $arr['titleAttribute']);
    }

    public function test_show_create_relation_button_default_false(): void
    {
        $field = MorphTo::make('commentable');

        $arr = $field->toArray();
        $this->assertFalse($arr['showCreateRelationButton']);
    }

    public function test_show_create_relation_button_enabled(): void
    {
        $field = MorphTo::make('commentable')->showCreateRelationButton();

        $arr = $field->toArray();
        $this->assertTrue($arr['showCreateRelationButton']);
    }

    public function test_hide_create_relation_button(): void
    {
        $field = MorphTo::make('commentable')
            ->showCreateRelationButton()
            ->hideCreateRelationButton();

        $arr = $field->toArray();
        $this->assertFalse($arr['showCreateRelationButton']);
    }

    public function test_modal_size_default(): void
    {
        $field = MorphTo::make('commentable');

        $arr = $field->toArray();
        $this->assertSame('2xl', $arr['modalSize']);
    }

    public function test_modal_size_custom_enum(): void
    {
        $field = MorphTo::make('commentable')->modalSize(ModalSize::Large);

        $arr = $field->toArray();
        $this->assertSame('lg', $arr['modalSize']);
    }

    public function test_modal_size_custom_string(): void
    {
        $field = MorphTo::make('commentable')->modalSize(ModalSize::FiveExtraLarge);

        $arr = $field->toArray();
        $this->assertSame('5xl', $arr['modalSize']);
    }

    public function test_relation_searchable_default_true(): void
    {
        $field = MorphTo::make('commentable');

        $arr = $field->toArray();
        $this->assertTrue($arr['relationSearchable']);
    }

    public function test_relation_searchable_disabled(): void
    {
        $field = MorphTo::make('commentable')->relationSearchable(false);

        $arr = $field->toArray();
        $this->assertFalse($arr['relationSearchable']);
    }

    public function test_nullable_field(): void
    {
        $field = MorphTo::make('commentable')->nullable();

        $arr = $field->toArray();
        $this->assertTrue($arr['nullable']);
    }

    public function test_with_subtitles_defaults_to_false(): void
    {
        $field = MorphTo::make('commentable');
        $arr = $field->toArray();
        $this->assertFalse($arr['withSubtitles']);
    }

    public function test_with_subtitles_can_be_enabled(): void
    {
        $field = MorphTo::make('commentable')->withSubtitles();
        $arr = $field->toArray();
        $this->assertTrue($arr['withSubtitles']);
        $this->assertSame('subtitle', $arr['subtitleAttribute']);
    }

    public function test_subtitle_attribute_enables_with_subtitles(): void
    {
        $field = MorphTo::make('commentable')->subtitleAttribute('excerpt');
        $arr = $field->toArray();
        $this->assertTrue($arr['withSubtitles']);
        $this->assertSame('excerpt', $arr['subtitleAttribute']);
    }

    public function test_peekable_defaults_to_true(): void
    {
        $field = MorphTo::make('commentable');
        $arr = $field->toArray();
        $this->assertTrue($arr['peekable']);
    }

    public function test_no_peeking_disables_peek(): void
    {
        $field = MorphTo::make('commentable')->noPeeking();
        $arr = $field->toArray();
        $this->assertFalse($arr['peekable']);
    }

    public function test_peekable_explicit_false(): void
    {
        $field = MorphTo::make('commentable')->peekable(false);
        $arr = $field->toArray();
        $this->assertFalse($arr['peekable']);
    }
}
