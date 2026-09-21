<?php

namespace Martis\Fields;

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
     * Prevent the user from editing existing keys.
     */
    public function disableEditingKeys(): static
    {
        $this->editingKeysDisabled = true;

        return $this;
    }

    /**
     * Prevent the user from adding new rows.
     */
    public function disableAddingRows(): static
    {
        $this->addingRowsDisabled = true;

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

        $model->setAttribute(
            $this->attribute,
            $this->storableStructuredValue($model, $this->attribute, $this->normalizeForStorage($value)),
        );
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
        ];
    }
}
