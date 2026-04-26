<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\CodeLanguage;

/**
 * Code editor field — provides syntax-highlighted code editing.
 *
 * Contexts:
 *  - index: hidden by default (not suitable as a column)
 *  - detail: displayed with syntax highlighting
 *  - create: code editor
 *  - update: code editor
 *
 * Notes:
 *  - Uses CodeMirror 6 (via @uiw/react-codemirror).
 *  - Language modes mapped to CodeMirror 6 extensions.
 */
class Code extends Field
{
    protected bool $isJson = false;

    protected CodeLanguage $language = CodeLanguage::Javascript;

    /**
     * Type.
     */
    public function type(): string
    {
        return 'code';
    }

    /**
     * Make.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    /**
     * Json.
     */
    public function json(): static
    {
        $this->isJson = true;

        return $this;
    }

    /**
     * Language.
     */
    public function language(CodeLanguage $language): static
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Is json.
     */
    public function isJson(): bool
    {
        return $this->isJson;
    }

    /**
     * Get language.
     */
    public function getLanguage(): CodeLanguage
    {
        return $this->language;
    }

    /**
     * Resolve.
     */
    public function resolve(Model $model, ?string $attribute = null): mixed
    {
        $value = parent::resolve($model, $attribute);

        if ($this->isJson && $value !== null) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return $value;
    }

    /**
     * Fill.
     */
    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute, $this->safeRequest());

            return;
        }

        if ($this->isJson && is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $model->setAttribute($this->attribute, $decoded);

                return;
            }
        }

        $model->setAttribute($this->attribute, $value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'json' => $this->isJson,
            'language' => $this->language->value,
        ];
    }
}
