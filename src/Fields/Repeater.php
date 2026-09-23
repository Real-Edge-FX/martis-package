<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Martis\Contracts\FieldContract;
use Martis\Enums\RepeaterStorage;
use Ramsey\Uuid\Uuid;

/**
 * Repeater field — repeatable row widget backed by JSON or HasMany.
 *
 * Storage modes:
 *  - JSON (`->asJson()`, default): stored as a serialized array on a model
 *    attribute with a `array`/`AsCollection` cast. Rows get a stable UUID
 *    auto-generated on create.
 *  - HasMany (`->asHasMany()` + `->uniqueField('uuid')`): rows live in a
 *    dedicated child table. Save performs a 3-way upsert (update existing
 *    by `uniqueField`, or by primary key without one, insert new, delete
 *    missing) so FKs downstream stay stable.
 *
 * A row the form sends back continues the stored row whose id it carries
 * (the `id` a read gives it). A field of the row that the request may not
 * write (readonly, computed, hidden from the user by `canSee()`, immutable
 * on a stored row) keeps the stored row's value, or takes its `default()`
 * on a new row; a field the user cannot see is left out of the schema and
 * of the rows a read gives (see `rowWritesField()`, `visibleRowValues()`).
 *
 * The fields inside the rows are validated on the server by every endpoint
 * that writes the Repeater (see `buildRowValidation()`).
 *
 * ⭐ Martis differentials:
 *  - `minRows(int)` / `maxRows(int)`: cardinality shown in the frontend
 *    (the add button disables at max, a notice shows below min).
 *  - `collapsible()` / `collapsedByDefault()` / `reorderable()` —
 *    collapse/expand chevron on each row header and drag handle to
 *    reorder (persists as the array position for JSON, as an auto-managed
 *    `position` column for HasMany when enabled).
 *  - `dependsOn([...])` — surfaces the parent record + sibling row values
 *    to each field inside a row (`useFieldContext`), enabling conditional
 *    rendering without leaving the row.
 *
 * @phpstan-type StoredRow array{id: int|string, type: string|null, fields: array<array-key, mixed>, model?: Model}
 */
class Repeater extends Field
{
    /** @var list<Repeatable> */
    protected array $repeatables = [];

    protected RepeaterStorage $storage = RepeaterStorage::Json;

    /**
     * Column that identifies a row across saves when in HasMany mode.
     * Required for HasMany upsert — without it, Martis would have to
     * delete and re-insert every row on save.
     */
    protected ?string $uniqueField = null;

    protected bool $confirmRemoval = false;

    // ⭐ Martis differentials
    protected ?int $minRows = null;

    protected ?int $maxRows = null;

    protected bool $collapsible = false;

    protected bool $collapsedByDefault = false;

    protected bool $reorderable = false;

    /**
     * Column name used to persist row order in HasMany mode when
     * `reorderable()` is on. Defaults to 'position'. Ignored in JSON.
     */
    protected ?string $orderColumn = null;

    /** Column that discriminates row types in Polymorphic mode. */
    protected string $typeColumn = 'type';

    /** Column that stores the serialised fields payload in Polymorphic mode. */
    protected string $payloadColumn = 'payload';

    /** Hide the per-row duplicate button (⭐ differential toolbar toggle). */
    protected bool $hideDuplicate = false;

    /** Hide the "Paste rows" bulk-import button (⭐ differential toolbar toggle). */
    protected bool $hideBulkPaste = false;

    // The Repeater forwards a list of parent-model attributes into row
    // scope so child fields can read them via the React `useFieldContext`
    // hook. Historically this was a private `$dependsOn` array on the
    // Repeater. Since v0.9.0 every field shares the same name with a
    // richer reactive callback (see `Field::dependsOn`); the Repeater
    // reuses the parent's `$dependentFields` so both APIs converge on
    // one wire-format key without breaking RepeaterField.tsx (which
    // continues to read a flat `dependsOn: string[]`).

    /**
     * ⭐ Pre-filled templates surfaced in the Add menu. Each entry is an
     * associative array with `label` (human name), `type` (Repeatable
     * shortName) and `fields` (the initial field values) — optionally an
     * `icon`/`color` for the menu entry.
     *
     * @var list<array{label: string, type: string, fields: array<string, mixed>, icon?: string|null, color?: string|null}>
     */
    protected array $rowTemplates = [];

    public function type(): string
    {
        return 'repeater';
    }

    /**
     * Declare the repeatable row types.
     *
     * When a single Repeatable is passed, the frontend renders a plain
     * "Add row" button. When multiple are passed, the Add button becomes a
     * dropdown so the user can choose which row type to insert.
     *
     * @param  list<Repeatable>  $repeatables
     */
    public function repeatables(array $repeatables): static
    {
        $this->repeatables = array_values($repeatables);

        return $this;
    }

    /** Persist rows as a serialised array on the parent model attribute. */
    public function asJson(): static
    {
        $this->storage = RepeaterStorage::Json;

        return $this;
    }

    /** Persist rows via the parent's HasMany relation. */
    public function asHasMany(): static
    {
        $this->storage = RepeaterStorage::HasMany;

        return $this;
    }

    /**
     * ⭐ Martis differential — persist every row type in a single child
     * table, discriminated by `$typeColumn` and with the field payload
     * serialised into `$payloadColumn` (JSON). All Repeatables must point
     * to the same Eloquent model.
     */
    public function asPolymorphic(string $typeColumn = 'type', string $payloadColumn = 'payload'): static
    {
        $this->storage = RepeaterStorage::Polymorphic;
        $this->typeColumn = $typeColumn;
        $this->payloadColumn = $payloadColumn;

        return $this;
    }

    /**
     * Set the column used to identify rows across saves. Required for
     * HasMany upsert. Strongly recommended for JSON too — when set,
     * Martis reads the value straight from the payload instead of
     * regenerating a UUID.
     */
    public function uniqueField(string $column): static
    {
        $this->uniqueField = $column;

        return $this;
    }

    /** Open a confirmation modal before removing a row. */
    public function confirmRemoval(bool $confirm = true): static
    {
        $this->confirmRemoval = $confirm;

        return $this;
    }

    // -------------------------------------------------------------------------
    // ⭐ Martis differentials
    // -------------------------------------------------------------------------

    /**
     * Minimum number of rows: the form shows a notice while the Repeater has
     * fewer. Add `->rules(['array', 'min:N'])` to reject fewer rows on the
     * server (`array` makes the message count items, not characters).
     */
    public function minRows(int $min): static
    {
        $this->minRows = max(0, $min);

        return $this;
    }

    /**
     * Maximum number of rows: the Add button disables at the limit. Add
     * `->rules(['array', 'max:N'])` to reject more rows on the server.
     */
    public function maxRows(int $max): static
    {
        $this->maxRows = max(0, $max);

        return $this;
    }

    /** Render a collapse/expand chevron on every row header. */
    public function collapsible(bool $enabled = true): static
    {
        $this->collapsible = $enabled;

        return $this;
    }

    /** Start with every row collapsed. Implies `collapsible()`. */
    public function collapsedByDefault(bool $enabled = true): static
    {
        if ($enabled) {
            $this->collapsible = true;
        }
        $this->collapsedByDefault = $enabled;

        return $this;
    }

    /**
     * Enable drag-and-drop reordering of rows.
     *
     * In JSON mode the final ordering is simply the array position.
     * In HasMany mode, Martis writes the order to `$orderColumn` (default
     * `'position'`) so the relation can `orderBy($orderColumn)` on reads.
     */
    public function reorderable(bool $enabled = true, ?string $orderColumn = null): static
    {
        $this->reorderable = $enabled;
        if ($orderColumn !== null) {
            $this->orderColumn = $orderColumn;
        }

        return $this;
    }

    /** {@inheritdoc} */
    public function dependsOn(array $attributes, ?\Closure $callback = null): static
    {
        // Forward to the parent: same array storage + optional callback.
        // Repeater's own usage ignores the closure (it only forwards
        // parent attributes into row context) but accepts it so the
        // signature stays compatible with the base `Field::dependsOn`.
        return parent::dependsOn($attributes, $callback);
    }

    /**
     * ⭐ Register a pre-filled row template.
     *
     * Templates surface alongside raw types in the Add menu, so users can
     * insert a row that comes with a sensible default payload (e.g. a
     * "Standard shipping" delivery phase with owner + effort already set).
     *
     * @param  string  $label  Human-readable label for the menu entry
     * @param  string  $type  Repeatable shortName this template fills
     * @param  array<string, mixed>  $fields  Initial field values
     * @param  array{icon?: string|null, color?: string|null}  $options  Optional decorations
     */
    public function rowTemplate(string $label, string $type, array $fields, array $options = []): static
    {
        $this->rowTemplates[] = [
            'label' => $label,
            'type' => $type,
            'fields' => $fields,
            'icon' => $options['icon'] ?? null,
            'color' => $options['color'] ?? null,
        ];

        return $this;
    }

    /**
     * Hide the per-row duplicate button (⭐ Martis differential toggle).
     * Useful when rows embed external IDs that shouldn't collide, or
     * when the team wants a stricter edit flow.
     */
    public function hideDuplicate(bool $hidden = true): static
    {
        $this->hideDuplicate = $hidden;

        return $this;
    }

    /**
     * Hide the "Paste rows" bulk-import button (⭐ Martis differential
     * toggle). Hide when the input shape is too free-form to parse or
     * when bulk imports should go through a dedicated endpoint.
     */
    public function hideBulkPaste(bool $hidden = true): static
    {
        $this->hideBulkPaste = $hidden;

        return $this;
    }

    /**
     * ⭐ Register many templates at once.
     *
     * @param  list<array{label: string, type: string, fields: array<string, mixed>, icon?: string|null, color?: string|null}>  $templates
     */
    public function rowTemplates(array $templates): static
    {
        foreach ($templates as $tpl) {
            $this->rowTemplate(
                (string) ($tpl['label'] ?? ''),
                (string) ($tpl['type'] ?? ''),
                is_array($tpl['fields'] ?? null) ? $tpl['fields'] : [],
                [
                    'icon' => $tpl['icon'] ?? null,
                    'color' => $tpl['color'] ?? null,
                ],
            );
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * The validation of the fields inside the rows a write sends: the rules,
     * the custom messages and the attribute names to give the validator next
     * to the Repeater's own rules.
     *
     * Each row validates the fields of its Repeatable (the one its `type`
     * names, or the first one when it names none, which is how every storage
     * mode reads such a row) under the row's path in `$data`: the `key` field
     * of row 1 of `sections` is `sections.1.fields.key`, named by the field's
     * label, so its error reads "The Key field is required.". A legacy flat
     * row of the JSON mode (no `type` and no `fields` key: the JSON mode
     * stores it as sent and reads the whole row as the first Repeatable's
     * fields) is checked under `sections.1.key`. A row whose `type` names no
     * Repeatable fails on `sections.1.type` instead of being stored with a
     * type no form can edit. A Repeater inside a row validates its own rows
     * the same way, under its path in the row.
     *
     * A row field validates with its `buildRules()` for the context of the
     * write, so `required()`, `nullable()`, `rules()` and `creationRules()`
     * (the record is created) / `updateRules()` (the record is updated) all
     * apply. Unlike a field of the record, a row field keeps `required` on
     * an update: every write replaces the stored rows with the rows it sends,
     * so a row always arrives whole, and a row without a required value would
     * be stored without it. A row field the row does not take from the
     * request is skipped (see `rowWritesField()`): a readonly or computed
     * one, one the user cannot see (`canSee()`), and an immutable one on a
     * stored row, which the rows of `$model` (the record the write updates)
     * tell apart from new ones; without `$model` every row is a new one. So
     * are file fields (`File`, `Image`, `Avatar`, `Audio`): a row does not
     * upload files, so what it sends for one is the stored path, which their
     * file rules would reject. So is the `unique()` helper, which excludes
     * the record an update writes: a row has no record of its own, so it
     * would reject every stored row sent back unchanged (a unique rule given
     * to `rules()` still applies). A field's custom messages follow it into
     * every row.
     *
     * Nothing is validated when the write stores no rows from the value: a
     * readonly Repeater, an immutable one on update, a computed one without a
     * `fillUsing()` callback, and a value that is not a list or a map (the
     * Repeater's own rules reject it). A `fillUsing()` callback receives the
     * rows the form sent, so its rows are validated.
     *
     * Every endpoint that writes a Repeater runs this; call it to validate a
     * Repeater's value the same way in a controller of your own.
     *
     * @param  array<array-key, mixed>  $data  The input the validator runs on.
     * @param  'create'|'update'|null  $context  The write: a create, an update, or neither (an Action's fields).
     * @param  Model|null  $model  The record the write updates, whose stored rows the rows sent continue.
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>, attributes: array<string, string>}
     */
    public function buildRowValidation(array $data, ?string $context = null, ?Model $model = null): array
    {
        $validation = ['rules' => [], 'messages' => [], 'attributes' => []];

        if ($this->writesRows($context)) {
            $request = $this->safeRequest() ?? Request::create('/');
            $stored = $model !== null ? $this->storedRows($model, $request) : [];
            $this->collectRowValidation($validation, $this->attribute(), data_get($data, $this->attribute()), $context, $request, $stored);
        }

        return $validation;
    }

    /**
     * Add the validation of the rows found at `$path` (their value is
     * `$rows`) to `$validation`. `$stored` holds the stored rows they
     * continue (see `storedRows()`).
     *
     * @param  array{rules: array<string, list<mixed>>, messages: array<string, string>, attributes: array<string, string>}  $validation
     * @param  'create'|'update'|null  $context
     * @param  array<string, StoredRow>  $stored
     */
    protected function collectRowValidation(array &$validation, string $path, mixed $rows, ?string $context, Request $request, array $stored = []): void
    {
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            // fill() drops an entry that is not a row.
            if (! is_array($row)) {
                continue;
            }

            $rowPath = "{$path}.{$index}";
            $repeatable = $this->repeatableForIncomingRow($row);

            if ($repeatable === null) {
                $shortNames = array_map(static fn (Repeatable $r): string => $r->shortName(), $this->repeatables);
                $validation['rules']["{$rowPath}.type"] = [Rule::in($shortNames)];
                $validation['attributes']["{$rowPath}.type"] = $this->rowTypeLabel();

                continue;
            }

            [, $continued] = $this->takeStoredRow($row, $repeatable, $stored);

            $flat = $this->isLegacyFlatRow($row);
            $fieldsPath = $flat ? $rowPath : "{$rowPath}.fields";
            $values = $flat ? $row : ($row['fields'] ?? null);

            foreach ($repeatable->fields($request) as $field) {
                // A file field (File, Image, Avatar, Audio) is skipped too: a
                // row does not upload files, so what it sends for one is the
                // stored path, which the field's file rules would reject.
                if (! $field instanceof Field || $field instanceof File || ! $this->rowWritesField($field, $continued !== null, $request)) {
                    continue;
                }

                // unique() excludes the record an update writes (the
                // controllers give it that record's key), and a row has none:
                // it would reject every stored row sent back unchanged, so it
                // is left out here. A rule given to rules() still applies.
                if ($field->uniqueConfig !== null) {
                    $field = clone $field;
                    $field->uniqueConfig = null;
                    $field->uniqueMessage = null;
                }

                $attribute = $field->attribute();
                $fieldPath = "{$fieldsPath}.{$attribute}";

                $validation['rules'][$fieldPath] = $field->buildRules($context);
                $validation['attributes'][$fieldPath] = $field->label();

                // A custom message is keyed `{attribute}.{rule}` (a unique()
                // message): move it to the field's path in the row.
                foreach ($field->validationMessages() as $key => $message) {
                    if (str_starts_with($key, $attribute.'.')) {
                        $validation['messages'][$fieldPath.substr($key, strlen($attribute))] = $message;
                    }
                }

                if ($field instanceof self) {
                    $inner = is_array($values) ? ($values[$attribute] ?? null) : null;
                    $innerStored = $field->indexJsonRows($continued['fields'][$attribute] ?? null);
                    $field->collectRowValidation($validation, $fieldPath, $inner, $context, $request, $innerStored);
                }
            }
        }
    }

    /**
     * Whether a write stores the rows it sends for this Repeater: not for a
     * readonly Repeater, an immutable one on update (every update skips it),
     * or a computed one without a `fillUsing()` callback.
     *
     * @param  'create'|'update'|null  $context
     */
    protected function writesRows(?string $context): bool
    {
        if ($this->isReadonly() || ($context === 'update' && $this->isImmutable())) {
            return false;
        }

        return $this->fillCallback !== null || ! $this->computed;
    }

    /**
     * The Repeatable an incoming row belongs to: the one its `type` names, or
     * the first one when the row names none (no `type`, `null` or an empty
     * string), which is how every storage mode reads it. `null` when the type
     * names no Repeatable.
     *
     * @param  array<array-key, mixed>  $row
     */
    protected function repeatableForIncomingRow(array $row): ?Repeatable
    {
        $type = $row['type'] ?? null;

        if ($type === null || $type === '') {
            return $this->repeatables[0] ?? null;
        }

        return is_string($type) || is_int($type) ? $this->findRepeatableByShortName((string) $type) : null;
    }

    /**
     * Whether an incoming row is a legacy flat row: no `type` and no `fields`
     * key. Only the JSON mode keeps one (it stores the row as sent and reads
     * the whole row as the first Repeatable's fields); the child-table modes
     * write a row's `fields` only, so there such a row is a row with no
     * values.
     *
     * @param  array<array-key, mixed>  $row
     */
    protected function isLegacyFlatRow(array $row): bool
    {
        return $this->storage === RepeaterStorage::Json
            && ! array_key_exists('type', $row)
            && ! array_key_exists('fields', $row);
    }

    /** The name a row type error uses ("The selected Row type is invalid."). */
    protected function rowTypeLabel(): string
    {
        $label = __('martis::messages.repeater_row_type');

        return is_string($label) ? $label : 'Row type';
    }

    // -------------------------------------------------------------------------
    // The row fields a write takes from the request
    // -------------------------------------------------------------------------

    /**
     * Whether a row takes the value of `$field` from the request: not when
     * the user cannot see the field (`canSee()`), when it is readonly or
     * computed, nor when it is immutable and the row is a stored one (a row
     * of the record the write updates). Such a field keeps the value of the
     * stored row the row continues, and a new row gives it its `default()`
     * (see `protectRowValues()`), as a field of the record never takes its
     * value from the request when it is readonly, hidden from the user, or
     * immutable on an update.
     */
    protected function rowWritesField(FieldContract $field, bool $storedRow, Request $request): bool
    {
        if (! $field->isAuthorizedToSee($request)) {
            return false;
        }

        if (! $field instanceof Field) {
            return true;
        }

        if ($field->isReadonly() || $field->isComputed()) {
            return false;
        }

        return ! ($storedRow && $field->isImmutable());
    }

    /**
     * The value a new row stores for a field it does not take from the
     * request: the field's `default()`, and none (null) for a computed field,
     * which stores nothing.
     */
    protected function newRowValue(FieldContract $field): mixed
    {
        return $field instanceof Field && ! $field->isComputed() ? $field->getDefaultValue() : null;
    }

    /**
     * The values a row writes: the ones the request sends (`$values`), where
     * each field the row does not take from the request (see
     * `rowWritesField()`) holds the value of the stored row it continues
     * (`$stored`, null for a new row) or, on a new row, its default, and is
     * left out when it has neither. A Repeater among the fields writes its
     * own rows the same way, against the stored row's. A key that names no
     * field of the row type is kept as sent.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>|null  $stored
     * @return array<array-key, mixed>
     */
    protected function protectRowValues(Repeatable $repeatable, array $values, ?array $stored, Request $request): array
    {
        foreach ($repeatable->fields($request) as $field) {
            $attribute = $field->attribute();

            if ($this->rowWritesField($field, $stored !== null, $request)) {
                if ($field instanceof self && is_array($values[$attribute] ?? null)) {
                    $values[$attribute] = $field->protectJsonRows($values[$attribute], $stored[$attribute] ?? null, $request);
                }

                continue;
            }

            $kept = $stored !== null && array_key_exists($attribute, $stored);
            $value = $kept ? $stored[$attribute] : ($stored === null ? $this->newRowValue($field) : null);

            if ($kept || $value !== null) {
                $values[$attribute] = $value;
            } else {
                unset($values[$attribute]);
            }
        }

        return $values;
    }

    /**
     * `$row` with its values written by `protectRowValues()` against the
     * stored row it continues (`$continued`, null for a new row): the row's
     * `fields`, or the row itself for a legacy flat row of the JSON mode,
     * whose values sit next to its `id`. A wrapped row loses the `title` a
     * read derives for it (a flat row's `title` is one of its values).
     *
     * @param  array<array-key, mixed>  $row
     * @param  StoredRow|null  $continued
     * @return array<array-key, mixed>
     */
    protected function protectRow(array $row, Repeatable $repeatable, ?array $continued, Request $request): array
    {
        $stored = $continued['fields'] ?? null;

        if ($this->isLegacyFlatRow($row)) {
            $values = $row;
            unset($values['id']);

            return array_intersect_key($row, ['id' => true]) + $this->protectRowValues($repeatable, $values, $stored, $request);
        }

        $values = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $row['fields'] = $this->protectRowValues($repeatable, $values, $stored, $request);
        unset($row['title']);

        return $row;
    }

    /**
     * The rows to store in a JSON value: the rows `$value` sends, each one
     * written by `protectRow()` against the stored row of `$storedValue` (the
     * value before the write) it continues. A row keeps the id of the stored
     * row it continues; a new row keeps the one it sends in the unique field
     * (`id` by default) or gets a UUID, so every stored row carries one.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function protectJsonRows(mixed $value, mixed $storedValue, Request $request): array
    {
        $stored = $this->indexJsonRows($storedValue);
        $uniqueKey = $this->uniqueField ?? 'id';
        $taken = [];
        $rows = [];

        foreach ($this->normalizeIncomingRows($value) as $row) {
            $repeatable = $this->repeatableForIncomingRow($row) ?? $this->repeatables[0] ?? null;
            $entry = null;

            if ($repeatable !== null) {
                [$entry, $continued] = $this->takeStoredRow($row, $repeatable, $stored);
                $row = $this->protectRow($row, $repeatable, $continued, $request);
            }

            $id = $entry['id'] ?? $row[$uniqueKey] ?? null;
            if ($entry === null && (! self::isRowId($id) || isset($taken[(string) $id]))) {
                $id = (string) Str::uuid();
            }

            $row[$uniqueKey] = $id;
            $taken[(string) $id] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The rows a `fillUsing()` callback receives: the value the request
     * sends, each row written by `protectRow()` against the stored row it
     * continues among the rows `$model` stores, so the callback gets the
     * values the built-in storage would write. A value that is not a list or
     * a map, and an entry that is not a row, reach it as sent.
     */
    protected function protectRowsForCallback(Model $model, mixed $value, Request $request): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $stored = $this->storedRows($model, $request);

        foreach ($value as $key => $row) {
            $repeatable = is_array($row) ? ($this->repeatableForIncomingRow($row) ?? $this->repeatables[0] ?? null) : null;

            if (is_array($row) && $repeatable !== null) {
                [, $continued] = $this->takeStoredRow($row, $repeatable, $stored);
                $value[$key] = $this->protectRow($row, $repeatable, $continued, $request);
            }
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Stored rows and the ids that match them
    // -------------------------------------------------------------------------

    /**
     * The stored row an incoming row continues, taken out of `$stored` so
     * that no two rows continue the same one: the stored row whose id the
     * row carries (see `incomingRowId()`). Returns the entry taken and, when
     * the row is of the entry's type (a HasMany entry records none), the
     * stored row the row continues. A row that takes the id of a stored row
     * of another type replaces that row and continues nothing of it.
     *
     * @param  array<array-key, mixed>  $row
     * @param  array<string, StoredRow>  $stored
     * @return array{0: StoredRow|null, 1: StoredRow|null}
     */
    protected function takeStoredRow(array $row, Repeatable $repeatable, array &$stored): array
    {
        $id = $this->incomingRowId($row);

        if ($id === null || ! isset($stored[$id])) {
            return [null, null];
        }

        $entry = $stored[$id];
        unset($stored[$id]);

        $sameType = $entry['type'] === null || $entry['type'] === $repeatable->shortName();

        return [$entry, $sameType ? $entry : null];
    }

    /**
     * The id an incoming row carries: its unique field when it sends one,
     * else its `id`, which holds the id the read gave the row (see
     * `resolve()`). Null for a row that carries none, a new row.
     *
     * @param  array<array-key, mixed>  $row
     */
    protected function incomingRowId(array $row): ?string
    {
        foreach ([$this->uniqueField ?? 'id', 'id'] as $key) {
            $id = $row[$key] ?? null;

            if (self::isRowId($id)) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Whether `$value` can identify a row: an integer or a non-empty string.
     *
     * @phpstan-assert-if-true int|non-empty-string $value
     */
    protected static function isRowId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && $value !== '');
    }

    /**
     * The rows this Repeater stores on `$model`, keyed by the id a read
     * gives each one: the rows of its JSON value (see `indexJsonRows()`), or
     * the children of its relation (see `indexChildRows()`), none while the
     * record is not stored yet.
     *
     * @return array<string, StoredRow>
     */
    protected function storedRows(Model $model, Request $request): array
    {
        $attribute = $this->attribute();

        if ($this->storage === RepeaterStorage::Json) {
            return $this->indexJsonRows($this->storedJsonValue($model));
        }

        if (! $model->exists || ! method_exists($model, $attribute)) {
            return [];
        }

        $relation = $model->{$attribute}();

        return $relation instanceof EloquentHasMany ? $this->indexChildRows($relation->get(), $request) : [];
    }

    /**
     * The value a JSON Repeater stores on `$model`, read as `resolve()` reads
     * it (through the attribute's cast or accessor). An attribute the record
     * neither holds nor reads through an accessor stores nothing: reading it
     * would take a method of the model with its name for a relationship.
     */
    protected function storedJsonValue(Model $model): mixed
    {
        $attribute = $this->attribute();

        if (array_key_exists($attribute, $model->getAttributes())
            || $model->hasGetMutator($attribute)
            || $model->hasAttributeGetMutator($attribute)) {
            return $model->getAttribute($attribute);
        }

        return null;
    }

    /**
     * The rows of a stored JSON value (a list of rows or its JSON), keyed by
     * the id a read gives each one (see `storedJsonRowId()`), with the row
     * type it names and its values.
     *
     * @return array<string, StoredRow>
     */
    protected function indexJsonRows(mixed $value): array
    {
        $stored = [];

        foreach ($this->jsonRowsOf($value) as $position => $row) {
            [$type, $fields] = $this->jsonRowTypeAndFields($row);
            $id = $this->storedJsonRowId($row, $position);
            $stored[(string) $id] ??= ['id' => $id, 'type' => $type, 'fields' => $fields];
        }

        return $stored;
    }

    /**
     * The children of a HasMany or polymorphic Repeater, keyed by the id a
     * read gives each one (see `childRowId()`), with their values: the
     * payload and the row type of a polymorphic child, the columns of its
     * row type's fields for a HasMany child (whose row type is not recorded).
     *
     * @param  iterable<Model>  $children
     * @return array<string, StoredRow>
     */
    protected function indexChildRows(iterable $children, Request $request): array
    {
        $stored = [];

        foreach ($children as $child) {
            $id = $this->childRowId($child);

            if (isset($stored[(string) $id])) {
                continue;
            }

            $repeatable = $this->findRepeatableForRow($child);

            if ($this->storage === RepeaterStorage::Polymorphic) {
                $stored[(string) $id] = ['id' => $id, 'type' => $repeatable?->shortName(), 'fields' => $this->payloadOf($child), 'model' => $child];

                continue;
            }

            $fields = [];
            $attributes = $child->getAttributes();
            foreach ($repeatable?->fields($request) ?? [] as $field) {
                if (array_key_exists($field->attribute(), $attributes)) {
                    $fields[$field->attribute()] = $child->getAttribute($field->attribute());
                }
            }

            $stored[(string) $id] = ['id' => $id, 'type' => null, 'fields' => $fields, 'model' => $child];
        }

        return $stored;
    }

    /**
     * The id of a stored JSON row: its unique field (`id` by default), else
     * its `id`, else, for a row stored without one (written outside Martis),
     * an id derived from its position among the rows, the same on every
     * read, so that the form sends the row back as the one it continues.
     *
     * @param  array<array-key, mixed>  $row
     */
    protected function storedJsonRowId(array $row, int $position): int|string
    {
        foreach ([$this->uniqueField ?? 'id', 'id'] as $key) {
            $id = $row[$key] ?? null;

            if (self::isRowId($id)) {
                return $id;
            }
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, "martis-repeater-row:{$this->attribute()}:{$position}")->toString();
    }

    /**
     * The id a read gives a child row: its unique column, or its primary key
     * when the Repeater has no unique field or the column is empty.
     */
    protected function childRowId(Model $child): int|string
    {
        if ($this->uniqueField !== null) {
            $id = $child->getAttribute($this->uniqueField);

            if (self::isRowId($id)) {
                return $id;
            }
        }

        $key = $child->getKey();

        return self::isRowId($key) ? $key : '';
    }

    /**
     * The rows of a stored JSON value (a list of rows or its JSON), in
     * order: the entries that are rows.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function jsonRowsOf(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * The row type a stored JSON row names and its values. A row that names
     * no type (no `type`, `null` or an empty string) is of the first row
     * type, and so is a legacy flat row (no `type` and no `fields` key),
     * whose values are the whole row but its `id`.
     *
     * @param  array<array-key, mixed>  $row
     * @return array{0: string|null, 1: array<array-key, mixed>}
     */
    protected function jsonRowTypeAndFields(array $row): array
    {
        $first = ($this->repeatables[0] ?? null)?->shortName();

        if (! array_key_exists('fields', $row) && ! array_key_exists('type', $row)) {
            unset($row['id']);

            return [$first, $row];
        }

        $type = $row['type'] ?? null;

        return [
            (is_string($type) && $type !== '') || is_int($type) ? (string) $type : $first,
            is_array($row['fields'] ?? null) ? $row['fields'] : [],
        ];
    }

    /**
     * The field values a polymorphic child stores in its payload column.
     *
     * @return array<array-key, mixed>
     */
    protected function payloadOf(Model $child): array
    {
        $raw = $child->getAttribute($this->payloadColumn);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function hasStructuredValue(): bool
    {
        return true;
    }

    /**
     * The rows of the record, each as `{id, type, fields}` (plus the `title`
     * a Closure-titled row type resolves), with the values of the fields the
     * user can see only (see `visibleRowValues()`). `id` is the id the form
     * sends back to continue the row: the unique field, or the child's
     * primary key, or for a JSON row stored without one an id derived from
     * its position.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute();
        $request = $this->safeRequest() ?? Request::create('/');

        if ($this->storage === RepeaterStorage::HasMany || $this->storage === RepeaterStorage::Polymorphic) {
            $relation = $model->{$attr}();
            if (! $relation instanceof EloquentHasMany) {
                return [];
            }

            if ($this->reorderable && $this->orderColumnName() !== null) {
                $relation->getQuery()->orderBy($this->orderColumnName());
            }

            $rows = $relation->getResults();
            $order = 0;
            $serialized = [];
            foreach ($rows as $row) {
                $serialized[] = $this->serializeRow($row, $order++, $request);
            }

            return $serialized;
        }

        return $this->resolveRows($model->getAttribute($attr));
    }

    /**
     * The rows a read gives for a JSON value holding them (a list of rows or
     * its JSON), as `resolve()` gives the rows of a JSON Repeater: a pivot
     * Repeater's value is read through this.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveRows(mixed $value): array
    {
        $request = $this->safeRequest() ?? Request::create('/');
        $rows = [];

        foreach ($this->jsonRowsOf($value) as $index => $row) {
            $rows[] = $this->normalizeJsonRow($row, $index, $request);
        }

        return $rows;
    }

    public function resolveForDisplay(Model $model, ?string $attribute = null): mixed
    {
        return $this->resolve($model, $attribute);
    }

    public function fill(Model $model, mixed $value): void
    {
        if ($this->isReadonly()) {
            return;
        }

        $request = $this->safeRequest() ?? Request::create('/');

        if ($this->fillCallback !== null) {
            // The callback writes the rows. It gets them with the fields they
            // do not take from the request already set, as the built-in
            // storage writes them.
            ($this->fillCallback)($model, $this->protectRowsForCallback($model, $value, $request), $this->attribute, $this->safeRequest());

            return;
        }

        // A computed field has no backing attribute or relation to write (see Field::fill()).
        if ($this->computed) {
            return;
        }

        $rows = $this->normalizeIncomingRows($value);

        if ($this->storage === RepeaterStorage::HasMany || $this->storage === RepeaterStorage::Polymorphic) {
            // Both child-table strategies need the parent to exist before we
            // can upsert children. Queue the rows in the deferred sync
            // registry; the controller flushes right after `$model->save()`.
            DeferredRepeaterSync::register($model, $this, $rows);

            return;
        }

        // JSON mode: each row continues the stored row whose id it carries,
        // and carries a stable id once stored.
        $model->setAttribute($this->attribute(), $this->protectJsonRows($rows, $this->storedJsonValue($model), $request));
    }

    /**
     * Flush pending HasMany / Polymorphic rows after the parent model has
     * been saved. Called by the controller via `DeferredRepeaterSync`.
     *
     * Each row continues the child whose id it carries (the unique column,
     * or the primary key when the Repeater has no unique field), which is
     * updated in place; a row that carries no stored child's id creates a
     * child, and the children whose rows the write no longer sends are
     * deleted. A row writes its values with `protectRowValues()` (a
     * polymorphic payload) or `rowColumnValues()` (HasMany columns).
     *
     * @param  array<array-key, mixed>  $rows
     */
    public function saveRelated(Model $parent, array $rows): void
    {
        $relation = $parent->{$this->attribute()}();
        if (! $relation instanceof EloquentHasMany) {
            return;
        }

        $request = $this->safeRequest() ?? Request::create('/');
        $unique = $this->uniqueField;
        $orderCol = $this->orderColumnName();
        $existing = $relation->get();
        $stored = $this->indexChildRows($existing, $request);
        $isPoly = $this->storage === RepeaterStorage::Polymorphic;

        $kept = [];
        $order = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $repeatable = $this->repeatableForIncomingRow($row) ?? $this->repeatables[0] ?? null;
            if ($repeatable === null) {
                continue;
            }

            // In polymorphic mode, every repeatable shares a single model.
            $modelClass = $isPoly
                ? ($repeatable::$model ?? $this->repeatables[0]::$model)
                : $repeatable::$model;
            if ($modelClass === null) {
                continue;
            }

            $values = is_array($row['fields'] ?? null) ? $row['fields'] : [];

            [$entry, $continued] = $this->takeStoredRow($row, $repeatable, $stored);
            $child = $entry['model'] ?? null;

            if ($child === null) {
                /** @var Model $child */
                $child = new $modelClass;
                $child->{$relation->getForeignKeyName()} = $parent->{$relation->getLocalKeyName()};
                // The primary key is the database's to give.
                if ($unique !== null && $unique !== $child->getKeyName()) {
                    $child->{$unique} = self::isRowId($row[$unique] ?? null) ? $row[$unique] : (string) Str::uuid();
                }
            }

            if ($isPoly) {
                // Discriminator + JSON payload — fields never touch real columns.
                $child->{$this->typeColumn} = $repeatable->shortName();
                $child->{$this->payloadColumn} = $this->protectRowValues($repeatable, $values, $continued['fields'] ?? null, $request);
            } else {
                foreach ($this->rowColumnValues($repeatable, $values, $continued, $request) as $column => $value) {
                    $child->{$column} = $value;
                }
            }

            if ($orderCol !== null) {
                $child->{$orderCol} = $order;
            }

            $child->save();
            $kept[] = $child;
            $order++;
        }

        // Delete rows that were removed on the client.
        foreach ($existing as $child) {
            if (! in_array($child, $kept, true)) {
                $child->delete();
            }
        }
    }

    /**
     * The columns a HasMany row writes to its child, with their values: the
     * attributes of its row type's fields, computed ones excepted (a computed
     * field has no column). A field the row does not take from the request
     * (see `rowWritesField()`) is left to the stored child the row continues
     * (`$continued`), and a new child gets its default. Any other key a row
     * sends (the foreign key, the primary key, another column) is ignored, so
     * a row cannot move itself to another parent or write a column its form
     * does not show. The unique column takes only a value that identifies a
     * row, so an empty one never replaces the id a new child got.
     *
     * @param  array<array-key, mixed>  $values
     * @param  StoredRow|null  $continued
     * @return array<string, mixed>
     */
    protected function rowColumnValues(Repeatable $repeatable, array $values, ?array $continued, Request $request): array
    {
        $columns = [];

        foreach ($repeatable->fields($request) as $field) {
            if ($field instanceof Field && $field->isComputed()) {
                continue;
            }

            $attribute = $field->attribute();

            if (! $this->rowWritesField($field, $continued !== null, $request)) {
                $default = $continued === null ? $this->newRowValue($field) : null;
                if ($default !== null) {
                    $columns[$attribute] = $default;
                }

                continue;
            }

            if (! array_key_exists($attribute, $values)) {
                continue;
            }

            $value = $values[$attribute];
            if ($field instanceof self && is_array($value)) {
                $value = $field->protectJsonRows($value, $continued['fields'][$attribute] ?? null, $request);
            }

            if ($attribute === $this->uniqueField && ! self::isRowId($value)) {
                continue;
            }

            $columns[$attribute] = $value;
        }

        return $columns;
    }

    protected function orderColumnName(): ?string
    {
        if (! $this->reorderable) {
            return null;
        }

        return $this->orderColumn ?? 'position';
    }

    /**
     * A stored JSON row as a read gives it: `{id, type, fields}` (see
     * `resolve()`), without the values of the fields the user cannot see.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeJsonRow(array $row, int $index, ?Request $request = null): array
    {
        $request ??= $this->safeRequest() ?? Request::create('/');

        [$type, $fields] = $this->jsonRowTypeAndFields($row);
        $repeatable = $this->findRepeatableByShortName($type) ?? $this->repeatables[0] ?? null;
        $fields = $this->visibleRowValues($repeatable, $fields, $request);

        $payload = [
            'id' => $this->storedJsonRowId($row, $index),
            'type' => $type,
            'fields' => $fields,
        ];

        return $this->withResolvedTitle($payload, $repeatable, $fields, $index);
    }

    /**
     * The values of a row the user may read: without the fields of the row
     * type they cannot see (`canSee()`), and with the rows of a Repeater
     * among them read the same way. A key that names no field of the row
     * type is kept.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    protected function visibleRowValues(?Repeatable $repeatable, array $values, Request $request): array
    {
        foreach ($repeatable?->fields($request) ?? [] as $field) {
            $attribute = $field->attribute();

            if (! $field->isAuthorizedToSee($request)) {
                unset($values[$attribute]);

                continue;
            }

            if ($field instanceof self && is_array($values[$attribute] ?? null)) {
                $values[$attribute] = $field->resolveRows($values[$attribute]);
            }
        }

        return $values;
    }

    /**
     * Attach the row header a Closure-titled repeatable resolves on the
     * server. `Repeatable::title('{attr}')` templates are evaluated live by
     * the frontend and need nothing here; a `title(Closure)` can only run in
     * PHP, so its result travels with the row as `title` (1-based index, as
     * the Closure contract documents). The Closure receives the values the
     * user can see, the ones the row carries. Rows of a repeatable without a
     * Closure title keep their `{id, type, fields}` shape untouched.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<array-key, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function withResolvedTitle(array $payload, ?Repeatable $repeatable, array $fields, int $index): array
    {
        if ($repeatable === null || ! $repeatable->hasTitleCallback()) {
            return $payload;
        }

        $payload['title'] = $repeatable->resolveTitle($fields, $index + 1);

        return $payload;
    }

    /**
     * A HasMany or polymorphic child as a read gives it (see `resolve()`),
     * with the values of the fields the user can see only: a HasMany child
     * resolves no other field.
     *
     * @return array<string, mixed>
     */
    protected function serializeRow(Model $row, int $index, ?Request $request = null): array
    {
        $request ??= $this->safeRequest() ?? Request::create('/');

        $repeatable = $this->findRepeatableForRow($row);
        if ($repeatable === null) {
            return [];
        }

        if ($this->storage === RepeaterStorage::Polymorphic) {
            $fieldsPayload = $this->visibleRowValues($repeatable, $this->payloadOf($row), $request);
        } else {
            $fieldsPayload = [];
            foreach ($repeatable->fields($request) as $field) {
                if ($field->isAuthorizedToSee($request)) {
                    $fieldsPayload[$field->attribute()] = $field->resolveForDisplay($row);
                }
            }
        }

        return $this->withResolvedTitle([
            'id' => $this->childRowId($row),
            'type' => $repeatable->shortName(),
            'fields' => $fieldsPayload,
        ], $repeatable, $fieldsPayload, $index);
    }

    /**
     * Find the repeatable metadata that matches an existing row instance.
     *
     * Polymorphic mode: match by the discriminator column against each
     * repeatable's `shortName()`.
     * HasMany mode: match by model class.
     */
    protected function findRepeatableForRow(Model $row): ?Repeatable
    {
        if ($this->storage === RepeaterStorage::Polymorphic) {
            $typeValue = (string) $row->{$this->typeColumn};
            foreach ($this->repeatables as $repeatable) {
                if ($repeatable->shortName() === $typeValue) {
                    return $repeatable;
                }
            }

            return $this->repeatables[0] ?? null;
        }

        foreach ($this->repeatables as $repeatable) {
            $modelClass = $repeatable::$model;
            if ($modelClass !== null && $row instanceof $modelClass) {
                return $repeatable;
            }
        }

        return $this->repeatables[0] ?? null;
    }

    /**
     * The row type whose `shortName()` is `$shortName`, or null. The
     * per-field endpoints (a row picker's relatable options, a row Select's
     * remote options) read a row's fields through it.
     */
    public function findRepeatable(string $shortName): ?Repeatable
    {
        return $this->findRepeatableByShortName($shortName);
    }

    protected function findRepeatableByShortName(?string $shortName): ?Repeatable
    {
        if ($shortName === null) {
            return null;
        }
        foreach ($this->repeatables as $repeatable) {
            if ($repeatable->shortName() === $shortName) {
                return $repeatable;
            }
        }

        return null;
    }

    /** @return list<array<array-key, mixed>> */
    protected function normalizeIncomingRows(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * A row template as the form receives it: without the values of the
     * fields the user cannot see.
     *
     * @param  array{label: string, type: string, fields: array<string, mixed>, icon?: string|null, color?: string|null}  $template
     * @return array<string, mixed>
     */
    protected function visibleTemplate(array $template, Request $request): array
    {
        $repeatable = $this->findRepeatableByShortName($template['type']);

        if ($repeatable !== null) {
            $template['fields'] = $this->visibleRowValues($repeatable, $template['fields'], $request);
        }

        return $template;
    }

    /** @return array<string, mixed> */
    protected function extraAttributes(): array
    {
        // Defer to the bound HTTP request when the container has one
        // (normal controller flow). Fall back to a synthetic Request in
        // unit tests / artisan contexts where no request has been bound
        // yet — the Repeatables only need a Request shape to build their
        // field schema.
        $request = app()->bound('request') ? request() : Request::create('/');

        return [
            'storage' => $this->storage->value,
            'typeColumn' => $this->typeColumn,
            'payloadColumn' => $this->payloadColumn,
            'uniqueField' => $this->uniqueField,
            'confirmRemoval' => $this->confirmRemoval,
            'minRows' => $this->minRows,
            'maxRows' => $this->maxRows,
            'collapsible' => $this->collapsible,
            'collapsedByDefault' => $this->collapsedByDefault,
            'reorderable' => $this->reorderable,
            // Repeater keeps the legacy flat-array shape that
            // RepeaterField.tsx already consumes. The base Field
            // serialiser would emit `{fields, ...}` — here, in
            // `extraAttributes`, we override it with the simpler
            // string list because the Repeater's "depends on" is
            // attribute-forwarding, not request-time reactivity.
            'dependsOn' => $this->dependentFields(),
            'hideDuplicate' => $this->hideDuplicate,
            'hideBulkPaste' => $this->hideBulkPaste,
            // The default rows a create form starts with, as a read gives
            // rows: without the fields the user cannot see.
            'defaultValue' => is_array($default = $this->getDefaultValue()) ? $this->resolveRows($default) : $default,
            'rowTemplates' => array_map(
                fn (array $template): array => $this->visibleTemplate($template, $request),
                $this->rowTemplates,
            ),
            'repeatables' => array_map(
                fn (Repeatable $r) => $r->toArray($request),
                $this->repeatables,
            ),
        ];
    }
}
