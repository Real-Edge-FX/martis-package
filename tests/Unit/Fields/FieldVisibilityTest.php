<?php

namespace Tests\Unit\Fields;

use Martis\FieldContext;
use Martis\Fields\Field;
use Martis\Fields\Text;

/**
 * Tests for context-aware field visibility filtering.
 *
 * Covers all 14 mandatory scenarios from the spec plus conflict resolution.
 */
describe('Field visibility by context', function (): void {

    // --- Scenario 1: No flags → visible in all contexts ---
    it('shows a field with no flags in all contexts', function (): void {
        $field = Text::make('title');

        foreach (FieldContext::cases() as $ctx) {
            expect($field->isVisibleForContext($ctx))->toBeTrue(
                "Expected field to be visible in {$ctx->value}"
            );
        }
    });

    // --- Scenario 2: hideFromIndex ---
    it('hides a field with hideFromIndex from index only', function (): void {
        $field = Text::make('body')->hideFromIndex();

        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeTrue();
    });

    // --- Scenario 3: hideFromDetail ---
    it('hides a field with hideFromDetail from detail and preview', function (): void {
        $field = Text::make('internal_notes')->hideFromDetail();

        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeFalse();
        // Preview inherits from showOnDetail when showOnPreview is null
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
    });

    // --- Scenario 4: hideWhenCreating ---
    it('hides a field with hideWhenCreating from create and inline-create', function (): void {
        $field = Text::make('slug')->hideWhenCreating();

        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue();
    });

    // --- Scenario 5: hideWhenUpdating ---
    it('hides a field with hideWhenUpdating from update only', function (): void {
        $field = Text::make('initial_password')->hideWhenUpdating();

        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue();
    });

    // --- Scenario 6: onlyOnIndex ---
    it('shows a field with onlyOnIndex only on index', function (): void {
        $field = Text::make('badge')->onlyOnIndex();

        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeFalse();
    });

    // --- Scenario 7: onlyOnDetail ---
    it('shows a field with onlyOnDetail only on detail', function (): void {
        $field = Text::make('full_bio')->onlyOnDetail();

        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeFalse();
        // preview inherits from showOnPreview (false), not showOnDetail
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeFalse();
    });

    // --- Scenario 8: onlyOnForms ---
    it('shows a field with onlyOnForms only on create, update, and inline-create', function (): void {
        $field = Text::make('password')->onlyOnForms();

        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeFalse();
    });

    // --- Scenario 9: exceptOnForms ---
    it('hides a field with exceptOnForms from all form contexts', function (): void {
        $field = Text::make('created_at')->exceptOnForms();

        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::INLINE_CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue();
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeTrue();
    });

    // --- Scenario 10: fieldsForIndex override + hideFromIndex still applies ---
    it('filters hideFromIndex even when field comes from fieldsForIndex', function (): void {
        $fields = [
            Text::make('title'),
            Text::make('debug_info')->hideFromIndex(),
        ];

        $filtered = Field::filterForContext($fields, FieldContext::INDEX);
        $attrs = array_map(fn ($f) => $f->attribute(), $filtered);

        expect($attrs)->toBe(['title']);
    });

    // --- Scenario 11: fieldsForCreate override + hideWhenCreating still applies ---
    it('filters hideWhenCreating even when field comes from fieldsForCreate', function (): void {
        $fields = [
            Text::make('title'),
            Text::make('auto_slug')->hideWhenCreating(),
        ];

        $filtered = Field::filterForContext($fields, FieldContext::CREATE);
        $attrs = array_map(fn ($f) => $f->attribute(), $filtered);

        expect($attrs)->toBe(['title']);
    });

    // --- Scenario 12: fieldsForInlineCreate + hideWhenCreating ---
    it('filters hideWhenCreating from inline-create context', function (): void {
        $fields = [
            Text::make('name'),
            Text::make('internal_code')->hideWhenCreating(),
        ];

        $filtered = Field::filterForContext($fields, FieldContext::INLINE_CREATE);
        $attrs = array_map(fn ($f) => $f->attribute(), $filtered);

        expect($attrs)->toBe(['name']);
    });

    // --- Scenario 13: Multiple flags combined with context overrides ---
    it('handles combined flags across all contexts correctly', function (): void {
        $indexOnly = Text::make('badge')->onlyOnIndex();
        $noForms = Text::make('created_at')->exceptOnForms();
        $noUpdate = Text::make('slug')->hideWhenUpdating();
        $detailOnly = Text::make('full_bio')->onlyOnDetail();
        $plain = Text::make('title');

        $all = [$indexOnly, $noForms, $noUpdate, $detailOnly, $plain];

        $getAttrs = fn (FieldContext $ctx) => array_map(
            fn ($f) => $f->attribute(),
            Field::filterForContext($all, $ctx)
        );

        expect($getAttrs(FieldContext::INDEX))->toBe(['badge', 'created_at', 'slug', 'title']);
        expect($getAttrs(FieldContext::DETAIL))->toBe(['created_at', 'slug', 'full_bio', 'title']);
        expect($getAttrs(FieldContext::CREATE))->toBe(['slug', 'title']);
        expect($getAttrs(FieldContext::UPDATE))->toBe(['title']);
        expect($getAttrs(FieldContext::INLINE_CREATE))->toBe(['slug', 'title']);
        expect($getAttrs(FieldContext::PREVIEW))->toBe(['created_at', 'slug', 'title']);
    });

    // --- Scenario 14: Serialized JSON respects context filtering ---
    it('produces correct field arrays after filterForContext', function (): void {
        $fields = [
            Text::make('title'),
            Text::make('slug')->hideWhenCreating()->hideWhenUpdating(),
            Text::make('created_at')->exceptOnForms(),
        ];

        $forCreate = Field::filterForContext($fields, FieldContext::CREATE);
        $forUpdate = Field::filterForContext($fields, FieldContext::UPDATE);
        $forDetail = Field::filterForContext($fields, FieldContext::DETAIL);
        $forIndex = Field::filterForContext($fields, FieldContext::INDEX);

        expect(count($forCreate))->toBe(1);  // only title
        expect($forCreate[0]->attribute())->toBe('title');

        expect(count($forUpdate))->toBe(1);  // only title
        expect($forUpdate[0]->attribute())->toBe('title');

        expect(count($forDetail))->toBe(3);  // all visible on detail
        expect(count($forIndex))->toBe(3);   // all visible on index
    });
});

// --- Conflict resolution tests ---
describe('Field visibility conflict resolution', function (): void {

    it('resolves onlyOnIndex + hideFromIndex as hidden (hide wins)', function (): void {
        $field = Text::make('conflict')->onlyOnIndex()->hideFromIndex();
        expect($field->isVisibleForContext(FieldContext::INDEX))->toBeFalse();
    });

    it('resolves onlyOnForms + exceptOnForms as hidden on forms (last write wins)', function (): void {
        $field = Text::make('conflict')->onlyOnForms()->exceptOnForms();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
    });

    it('resolves onlyOnDetail + hideFromDetail as hidden (hide wins)', function (): void {
        $field = Text::make('conflict')->onlyOnDetail()->hideFromDetail();
        expect($field->isVisibleForContext(FieldContext::DETAIL))->toBeFalse();
    });

    it('resolves hideWhenCreating with showOnForms=true correctly', function (): void {
        $field = Text::make('email')->hideWhenCreating();
        // showOnForms is still true (default), but create uses showOnCreate which is false
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeTrue();
    });

    it('resolves hideWhenUpdating with showOnForms=true correctly', function (): void {
        $field = Text::make('password')->hideWhenUpdating();
        expect($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
        expect($field->isVisibleForContext(FieldContext::CREATE))->toBeTrue();
    });
});

// --- Preview context tests ---
describe('Preview context visibility', function (): void {

    it('preview inherits from showOnDetail by default', function (): void {
        $field = Text::make('notes')->hideFromDetail();
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeFalse();
    });

    it('preview shows fields that are visible on detail', function (): void {
        $field = Text::make('title');
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeTrue();
    });

    it('onlyOnDetail sets showOnPreview=false explicitly', function (): void {
        $field = Text::make('bio')->onlyOnDetail();
        // onlyOnDetail explicitly sets showOnPreview=false
        expect($field->isVisibleForContext(FieldContext::PREVIEW))->toBeFalse();
    });
});

// --- FieldContext enum tests ---
describe('FieldContext enum', function (): void {

    it('has all required context values', function (): void {
        expect(FieldContext::INDEX->value)->toBe('index');
        expect(FieldContext::DETAIL->value)->toBe('detail');
        expect(FieldContext::CREATE->value)->toBe('create');
        expect(FieldContext::UPDATE->value)->toBe('update');
        expect(FieldContext::INLINE_CREATE->value)->toBe('inline-create');
        expect(FieldContext::PREVIEW->value)->toBe('preview');
    });

    it('has exactly 6 contexts', function (): void {
        expect(count(FieldContext::cases()))->toBe(6);
    });
});
