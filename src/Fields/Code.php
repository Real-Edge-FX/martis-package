<?php

namespace Martis\Fields;

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\CodeLanguage;

/**
 * Code editor field — provides syntax-highlighted code editing.
 *
 * Paridade com Laravel Nova v5: Code field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields
 *
 * Contextos:
 *  - index: oculto por padrão (não adequado como coluna)
 *  - detail: exibição com syntax highlighting
 *  - create: editor de código
 *  - update: editor de código
 *
 * Divergências intencionais do Nova:
 *  - Usa CodeMirror 6 (via @uiw/react-codemirror) em vez de CodeMirror 5.
 *  - Language modes mapeados para extensões do CodeMirror 6.
 */
class Code extends Field
{
    protected bool $isJson = false;

    protected CodeLanguage $language = CodeLanguage::Javascript;

    public function type(): string
    {
        return 'code';
    }

    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    public function json(): static
    {
        $this->isJson = true;

        return $this;
    }

    public function language(CodeLanguage $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function isJson(): bool
    {
        return $this->isJson;
    }

    public function getLanguage(): CodeLanguage
    {
        return $this->language;
    }

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

    public function fill(Model $model, mixed $value): void
    {
        if ($this->readonly) {
            return;
        }

        if ($this->fillCallback !== null) {
            ($this->fillCallback)($model, $value, $this->attribute);

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
