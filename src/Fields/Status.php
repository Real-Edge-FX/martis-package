<?php

namespace Martis\Fields;

/**
 * Status field — visual state indicator with semantic loading/failed states.
 *
 * Paridade com Laravel Nova v5: Status field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields#status-field
 *
 * Status é semanticamente distinto do Badge:
 *  - Badge: indicador genérico de valor com cor
 *  - Status: indicador de estado/progresso com semântica de loading e falha
 *
 * Contextos:
 *  - index: sim (display-only)
 *  - detail: sim (display-only)
 *  - create/update: não renderizado como input nativo (oculto por padrão)
 *
 * Divergências intencionais do Nova:
 *  - Forms ocultados por padrão. Status não é um campo de formulário padrão.
 *  - Status não pode ser "depended upon" por outros fields no sistema de
 *    dependent fields — live-reporting de mudanças não é suportado.
 *
 * API:
 *  - loadingWhen(['value1', 'value2'])  — valores que renderizam spinner de loading
 *  - failedWhen(['value1', 'value2'])   — valores que renderizam indicador de erro
 *
 * Valores não listados são renderizados como estado "success" (concluído).
 */
class Status extends Field
{
    /** @var list<string> Values that trigger loading state */
    protected array $loadingWhen = [];

    /** @var list<string> Values that trigger failed state */
    protected array $failedWhen = [];

    public function type(): string
    {
        return 'status';
    }

    /**
     * Override make() to default to display-only (hidden from forms).
     * Status is a state indicator, not a form input.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromForms();
    }

    /**
     * Define which values trigger the loading state (spinner).
     *
     * Example:
     *   ->loadingWhen(['waiting', 'running', 'queued'])
     *
     * @param  list<string>  $values
     */
    public function loadingWhen(array $values): static
    {
        $this->loadingWhen = array_map('strval', $values);

        return $this;
    }

    /**
     * Define which values trigger the failed state (error indicator).
     *
     * Example:
     *   ->failedWhen(['failed', 'errored', 'cancelled'])
     *
     * @param  list<string>  $values
     */
    public function failedWhen(array $values): static
    {
        $this->failedWhen = array_map('strval', $values);

        return $this;
    }

    /** @return list<string> */
    public function getLoadingWhen(): array
    {
        return $this->loadingWhen;
    }

    /** @return list<string> */
    public function getFailedWhen(): array
    {
        return $this->failedWhen;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return [
            'loadingWhen' => $this->loadingWhen,
            'failedWhen' => $this->failedWhen,
        ];
    }
}
