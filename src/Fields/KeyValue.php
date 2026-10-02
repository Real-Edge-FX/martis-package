<?php

namespace Martis\Fields;

use ArrayObject;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

/**
 * KeyValue field — edits dynamic key-value pairs stored as JSON.
 *
 * Contexts:
 *  - create: yes
 *  - update: yes
 *  - detail: yes
 *  - index: no (hidden by default — not suitable as a table column)
 *
 * Notes:
 *  - Index hidden by default; developer can call ->showOnIndex() if needed.
 *  - KeyValue does not live-report changes to the dependent fields system.
 */
class KeyValue extends Field
{
    protected string $keyLabel = 'Key';

    protected string $valueLabel = 'Value';

    protected string $actionText = 'Add Row';

    protected bool $editingKeysDisabled = false;

    protected bool $addingRowsDisabled = false;

    protected bool $deletingRowsDisabled = false;

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'key_value';
    }

    /** {@inheritdoc} */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    /**
     * Set the label for the key column.
     */
    public function keyLabel(string $label): static
    {
        $this->keyLabel = $label;

        return $this;
    }

    /**
     * Set the label for the value column.
     */
    public function valueLabel(string $label): static
    {
        $this->valueLabel = $label;

        return $this;
    }

    /**
     * Set the label for the "add row" action button.
     */
    public function actionText(string $text): static
    {
        $this->actionText = $text;

        return $this;
    }

    /**
     * Prevent the user from editing existing keys. A write keeps the key set
     * too: a key that is not one of the stored (or default) keys is dropped.
     */
    public function disableEditingKeys(): static
    {
        $this->editingKeysDisabled = true;

        return $this;
    }

    /**
     * Prevent the user from adding new rows. A write keeps the key set too: a
     * key that is not one of the stored (or default) keys is dropped.
     */
    public function disableAddingRows(): static
    {
        $this->addingRowsDisabled = true;

        return $this;
    }

    /**
     * Prevent the user from deleting rows: the form renders no delete
     * button on any row, and a write that leaves a stored (or default) key
     * out gets its value back. Together with `disableEditingKeys()` and
     * `disableAddingRows()` this presents a fixed set of keys whose values
     * are the only editable part (Nova's `disableDeletingRows()`), and
     * `fill()` holds a request to that set as well, which Nova does not.
     */
    public function disableDeletingRows(): static
    {
        $this->deletingRowsDisabled = true;

        return $this;
    }

    /**
     * Get key label.
     */
    public function getKeyLabel(): string
    {
        return $this->keyLabel;
    }

    /**
     * Get value label.
     */
    public function getValueLabel(): string
    {
        return $this->valueLabel;
    }

    /**
     * Get action text.
     */
    public function getActionText(): string
    {
        return $this->actionText;
    }

    /**
     * Is editing keys disabled.
     */
    public function isEditingKeysDisabled(): bool
    {
        return $this->editingKeysDisabled;
    }

    /**
     * Is adding rows disabled.
     */
    public function isAddingRowsDisabled(): bool
    {
        return $this->addingRowsDisabled;
    }

    /**
     * Is deleting rows disabled.
     */
    public function isDeletingRowsDisabled(): bool
    {
        return $this->deletingRowsDisabled;
    }

    /** {@inheritdoc} */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $attr = $attribute ?? $this->attribute;
        $raw = $this->resolveAttribute($model, $attr);

        if ($this->resolveCallback !== null) {
            return ($this->resolveCallback)($raw, $model, $attr, $this->safeRequest());
        }

        return $this->decodeToRows($raw);
    }

    /** {@inheritdoc} */
    public function hasStructuredValue(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->isReadonly()) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        // A computed field has no backing attribute to write (see Field::fill()).
        if ($this->computed) {
            return;
        }

        $map = $this->normalizeForStorage($value);

        if ($this->editingKeysDisabled || $this->addingRowsDisabled || $this->deletingRowsDisabled) {
            $map = $this->enforceKeySet($model, $map ?? []);
        }

        $model->setAttribute(
            $this->attribute,
            $this->storableStructuredValue($model, $this->attribute, $map),
        );
    }

    /**
     * Hold a submitted map to the key set the field's flags fix, and to what
     * the form lets a user do, no more.
     *
     * `disableEditingKeys()`, `disableAddingRows()` and `disableDeletingRows()`
     * shape the form, and a request does not have to go through the form, so
     * `fill()` enforces them. The keys the user may rely on are the stored
     * map's for a record that has one, the field's `default()` keys for a new
     * record or one whose stored map is empty:
     *
     * - with `disableEditingKeys()` a key outside that set (a new row, or a
     *   key edited into another name) is dropped;
     * - without it a key can be edited, and a key edited into another name is
     *   a new key plus a missing one. Under `disableAddingRows()` that is a
     *   rename: a new key takes the place of a key of the set the submission
     *   leaves out, so it is kept and the old key is not restored beside it
     *   (only that many new keys are kept, the surplus, a real new row, is
     *   dropped). Without `disableAddingRows()` every new key is kept;
     * - a key of the set the submission leaves out (a deleted row, or the old
     *   name of a key edited into another one) takes its stored (or default)
     *   value back while rows cannot be deleted, unless a rename replaced it.
     *
     * The values of the keys that stay are the user's: they are the editable
     * part.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>|null
     */
    protected function enforceKeySet(Model $model, array $submitted): ?array
    {
        $stored = $model->exists ? $this->mapOf($model->getAttribute($this->attribute)) : [];
        $fixed = $stored !== [] ? $stored : $this->mapOf($this->getDefaultValue());

        $missing = [];
        foreach (array_keys($fixed) as $key) {
            if (! array_key_exists($key, $submitted)) {
                $missing[] = $key;
            }
        }

        $new = array_keys(array_diff_key($submitted, $fixed));

        if ($this->editingKeysDisabled) {
            $new = [];
        } elseif ($this->addingRowsDisabled) {
            // Only a rename (a new key for a missing one) keeps the row count.
            $new = array_slice($new, 0, count($missing));
        }

        // Under `disableAddingRows()` the missing keys a kept new key replaces
        // are renamed, not deleted: the old key must not come back beside the
        // new one, which would add a row. Without it a new key is a new row
        // and a deleted one is restored beside it.
        $renamed = $this->addingRowsDisabled ? array_slice($missing, 0, count($new)) : [];

        $map = [];

        foreach ($fixed as $key => $current) {
            if (array_key_exists($key, $submitted)) {
                $map[$key] = $submitted[$key];
            } elseif ($this->deletingRowsDisabled && ! in_array($key, $renamed, true)) {
                $map[$key] = $current;
            }
        }

        foreach ($new as $key) {
            $map[$key] = $submitted[$key];
        }

        return $map === [] ? null : $map;
    }

    /**
     * The map a stored value (or a default) holds: a JSON string, rows
     * `[{key, value}]`, an associative array or the object a cast hands
     * back. Values are kept as stored, so a restored row is not rewritten.
     *
     * @return array<string, mixed>
     */
    private function mapOf(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if ($raw instanceof Arrayable) {
            $raw = $raw->toArray();
        } elseif ($raw instanceof ArrayObject) {
            $raw = $raw->getArrayCopy();
        }

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $map = [];

        if (isset($raw[0])) {
            foreach ($raw as $row) {
                if (is_array($row) && isset($row['key'])) {
                    $map[(string) $row['key']] = $row['value'] ?? '';
                }
            }

            return $map;
        }

        foreach ($raw as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }

    /**
     * Decode raw value to rows format for the frontend.
     *
     * @return list<array{key: string, value: string}>
     */
    public function decodeToRows(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            // Already in rows format: [{key: 'k', value: 'v'}, ...]
            if (isset($raw[0]) && is_array($raw[0]) && array_key_exists('key', $raw[0])) {
                return array_values(array_map(
                    fn (mixed $row): array => [
                        'key' => (string) ($row['key'] ?? ''),
                        'value' => (string) ($row['value'] ?? ''),
                    ],
                    $raw,
                ));
            }

            // Associative array: ['foo' => 'bar'] → [{key: 'foo', value: 'bar'}]
            $rows = [];
            foreach ($raw as $k => $v) {
                $rows[] = ['key' => (string) $k, 'value' => (string) $v];
            }

            return $rows;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->decodeToRows($decoded);
            }
        }

        return [];
    }

    /**
     * Encode rows or associative array to JSON string for storage.
     * Storage format: {"key1":"value1","key2":"value2"}
     */
    public function encodeToJson(mixed $value): ?string
    {
        $normalized = $this->normalizeForStorage($value);

        return $normalized === null ? null : json_encode($normalized, JSON_THROW_ON_ERROR);
    }

    /**
     * Reduce rows, an associative array or a JSON string to the associative
     * map that gets stored, or `null` when there is nothing to store.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeForStorage(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $this->normalizeToAssociative($decoded) : null;
        }

        return is_array($value) ? $this->normalizeToAssociative($value) : null;
    }

    /**
     * Convert rows [{key, value}] or associative to {'key': 'value'} for storage.
     *
     * @param  array<mixed>  $value
     * @return array<string, string>
     */
    protected function normalizeToAssociative(array $value): array
    {
        // Empty
        if (empty($value)) {
            return [];
        }

        // Already associative (non-sequential integer keys)
        if (! isset($value[0])) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[(string) $k] = (string) $v;
            }

            return $result;
        }

        // Row format: [{key, value}]
        $assoc = [];
        foreach ($value as $row) {
            if (is_array($row) && isset($row['key'])) {
                $assoc[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $assoc;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'keyLabel' => $this->keyLabel,
            'valueLabel' => $this->valueLabel,
            'actionText' => $this->actionText,
            'editingKeysDisabled' => $this->editingKeysDisabled,
            'addingRowsDisabled' => $this->addingRowsDisabled,
            'deletingRowsDisabled' => $this->deletingRowsDisabled,
        ];
    }
}
