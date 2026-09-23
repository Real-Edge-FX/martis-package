<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Martis\Contracts\FieldContract;
use Martis\Enums\RepeaterStorage;

/**
 * Repeater field — repeatable row widget backed by JSON or HasMany.
 *
 * Storage modes:
 *  - JSON (`->asJson()`, default): stored as a serialized array on a model
 *    attribute with a `array`/`AsCollection` cast. Rows get a stable UUID
 *    auto-generated on create.
 *  - HasMany (`->asHasMany()` + `->uniqueField('uuid')`): rows live in a
 *    dedicated child table. Save performs a 3-way upsert (update existing
 *    by `uniqueField`, insert new, delete missing) so FKs downstream stay
 *    stable.
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
     * be stored without it. Readonly and computed row fields are skipped: the
     * row form cannot change them. So are file fields (`File`, `Image`,
     * `Avatar`, `Audio`): a row does not upload files, so what it sends for
     * one is the stored path, which their file rules would reject. So is the
     * `unique()` helper, which excludes the record an update writes: a row has
     * no record of its own, so it would reject every stored row sent back
     * unchanged (a unique rule given to `rules()` still applies). A field's
     * custom messages follow it into every row.
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
     * @return array{rules: array<string, list<mixed>>, messages: array<string, string>, attributes: array<string, string>}
     */
    public function buildRowValidation(array $data, ?string $context = null): array
    {
        $validation = ['rules' => [], 'messages' => [], 'attributes' => []];

        if ($this->writesRows($context)) {
            $request = $this->safeRequest() ?? Request::create('/');
            $this->collectRowValidation($validation, $this->attribute(), data_get($data, $this->attribute()), $context, $request);
        }

        return $validation;
    }

    /**
     * Add the validation of the rows found at `$path` (their value is
     * `$rows`) to `$validation`.
     *
     * @param  array{rules: array<string, list<mixed>>, messages: array<string, string>, attributes: array<string, string>}  $validation
     * @param  'create'|'update'|null  $context
     */
    protected function collectRowValidation(array &$validation, string $path, mixed $rows, ?string $context, Request $request): void
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

            $flat = $this->isLegacyFlatRow($row);
            $fieldsPath = $flat ? $rowPath : "{$rowPath}.fields";
            $values = $flat ? $row : ($row['fields'] ?? null);

            foreach ($repeatable->fields($request) as $field) {
                // A file field (File, Image, Avatar, Audio) is skipped too: a
                // row does not upload files, so what it sends for one is the
                // stored path, which the field's file rules would reject.
                if (! $field instanceof Field || $field instanceof File || $field->isReadonly() || $field->isComputed()) {
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
                    $field->collectRowValidation($validation, $fieldPath, $inner, $context, $request);
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
    // Resolution
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function hasStructuredValue(): bool
    {
        return true;
    }

    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute();

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
                $serialized[] = $this->serializeRow($row, $order++);
            }

            return $serialized;
        }

        $raw = $model->getAttribute($attr);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $index = 0;
        $rows = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $rows[] = $this->normalizeJsonRow($row, $index++);
            }
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

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

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

        // JSON mode: ensure each row carries a stable identifier.
        $uniqueKey = $this->uniqueField ?? 'id';
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (empty($row[$uniqueKey])) {
                $row[$uniqueKey] = (string) Str::uuid();
            }
            // `title` on a wrapped row is the header resolve() derived from
            // the row's Closure title; it is computed on every read, never
            // stored. (A legacy flat row has no `fields` wrapper, so a real
            // `title` attribute there is left alone.)
            if (isset($row['fields']) && is_array($row['fields'])) {
                unset($row['title']);
            }
            $normalized[] = $row;
        }

        $model->setAttribute($this->attribute(), $normalized);
    }

    /**
     * Flush pending HasMany / Polymorphic rows after the parent model has
     * been saved. Called by the controller via `DeferredRepeaterSync`.
     */
    public function saveRelated(Model $parent, array $rows): void
    {
        $relation = $parent->{$this->attribute()}();
        if (! $relation instanceof EloquentHasMany) {
            return;
        }

        $unique = $this->uniqueField;
        $orderCol = $this->orderColumnName();
        $existing = $relation->get();
        $isPoly = $this->storage === RepeaterStorage::Polymorphic;

        $keptKeys = [];
        $order = 0;
        /** @var array<string, list<string>> $columns */
        $columns = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = isset($row['type']) ? (string) $row['type'] : null;
            $repeatable = $this->findRepeatableByShortName($type);
            if ($repeatable === null) {
                $repeatable = $this->repeatables[0] ?? null;
            }
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

            $fields = $row['fields'] ?? [];
            if (! is_array($fields)) {
                $fields = [];
            }

            $uniqueValue = $unique !== null ? ($row[$unique] ?? null) : null;

            $child = null;
            if ($unique !== null && $uniqueValue !== null) {
                $child = $existing->firstWhere($unique, $uniqueValue);
            }

            if ($child === null) {
                /** @var Model $child */
                $child = new $modelClass;
                $child->{$relation->getForeignKeyName()} = $parent->{$relation->getLocalKeyName()};
                if ($unique !== null && $uniqueValue === null) {
                    $uniqueValue = (string) Str::uuid();
                }
                if ($unique !== null && $uniqueValue !== null) {
                    $child->{$unique} = $uniqueValue;
                }
            }

            if ($isPoly) {
                // Discriminator + JSON payload — fields never touch real columns.
                $child->{$this->typeColumn} = $repeatable->shortName();
                $child->{$this->payloadColumn} = $fields;
            } else {
                // Only the row's own fields reach the child's columns: any
                // other key a row sends (the foreign key, the primary key,
                // another column) is ignored, so a row cannot move itself to
                // another parent or write a column its form does not show.
                $columns[$repeatable->shortName()] ??= $this->rowColumns($repeatable);
                foreach ($columns[$repeatable->shortName()] as $attr) {
                    if (array_key_exists($attr, $fields)) {
                        $child->{$attr} = $fields[$attr];
                    }
                }
            }

            if ($orderCol !== null) {
                $child->{$orderCol} = $order;
            }

            $child->save();
            if ($unique !== null) {
                $keptKeys[] = $child->{$unique};
            } else {
                $keptKeys[] = $child->getKey();
            }
            $order++;
        }

        // Delete rows that were removed on the client.
        $keyName = $unique ?? $existing->first()?->getKeyName() ?? 'id';
        $toDelete = $existing->filter(fn (Model $row) => ! in_array($row->{$keyName}, $keptKeys, true));
        foreach ($toDelete as $row) {
            $row->delete();
        }
    }

    /**
     * The columns a HasMany row of `$repeatable` writes: the attributes of its
     * fields, except computed ones (a computed field has no column).
     *
     * @return list<string>
     */
    protected function rowColumns(Repeatable $repeatable): array
    {
        $columns = [];
        foreach ($repeatable->fields($this->safeRequest() ?? Request::create('/')) as $field) {
            if ($field instanceof Field && $field->isComputed()) {
                continue;
            }
            $columns[] = $field->attribute();
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
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeJsonRow(array $row, int $index): array
    {
        $id = $row[$this->uniqueField ?? 'id'] ?? $row['id'] ?? null;
        $type = $row['type'] ?? ($this->repeatables[0]?->shortName());
        $fields = isset($row['fields']) && is_array($row['fields']) ? $row['fields'] : [];

        if (! array_key_exists('fields', $row) && ! array_key_exists('type', $row)) {
            // Legacy flat row (no wrapper). Treat the whole row as the field
            // map. Checked on the raw keys: `$type` already carries the first
            // repeatable's shortName as its default, so it is never null here.
            $copy = $row;
            unset($copy['id'], $copy['type']);
            $fields = $copy;
            $type = $this->repeatables[0]?->shortName();
        }

        $payload = [
            'id' => $id,
            'type' => $type,
            'fields' => $fields,
        ];

        $repeatable = $this->findRepeatableByShortName($type) ?? $this->repeatables[0] ?? null;

        return $this->withResolvedTitle($payload, $repeatable, $fields, $index);
    }

    /**
     * Attach the row header a Closure-titled repeatable resolves on the
     * server. `Repeatable::title('{attr}')` templates are evaluated live by
     * the frontend and need nothing here; a `title(Closure)` can only run in
     * PHP, so its result travels with the row as `title` (1-based index, as
     * the Closure contract documents). Rows of a repeatable without a
     * Closure title keep their `{id, type, fields}` shape untouched.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fields
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
     * @return array<string, mixed>
     */
    protected function serializeRow(Model $row, int $index): array
    {
        $repeatable = $this->findRepeatableForRow($row);
        if ($repeatable === null) {
            return [];
        }

        $fieldsPayload = [];

        if ($this->storage === RepeaterStorage::Polymorphic) {
            $raw = $row->{$this->payloadColumn};
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            $fieldsPayload = is_array($raw) ? $raw : [];
        } else {
            foreach ($repeatable->fields(request()) as $field) {
                if (! $field instanceof FieldContract) {
                    continue;
                }
                $fieldsPayload[$field->attribute()] = $field->resolveForDisplay($row);
            }
        }

        return $this->withResolvedTitle([
            'id' => $this->uniqueField !== null ? $row->{$this->uniqueField} : $row->getKey(),
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

    /** @return list<array<string, mixed>> */
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
            'rowTemplates' => $this->rowTemplates,
            'repeatables' => array_map(
                fn (Repeatable $r) => $r->toArray($request),
                $this->repeatables,
            ),
        ];
    }
}
