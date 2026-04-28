<?php

namespace Martis\Fields;

/**
 * Status field — visual state indicator with semantic loading/failed states.
 *
 * Status is semantically distinct from Badge:
 *  - Badge: generic value indicator with color
 *  - Status: state/progress indicator with loading and failed semantics
 *
 * Contexts:
 *  - index: yes (display-only)
 *  - detail: yes (display-only)
 *  - create/update: not rendered as a native input (hidden by default)
 *
 * Notes:
 *  - Forms hidden by default. Status is not a standard form field.
 *  - Status cannot be "depended upon" by other fields in the
 *    dependent fields system — live change reporting is not supported.
 *
 * API:
 *  - loadingWhen(['value1', 'value2'])  — valores que renderizam spinner de loading
 *  - failedWhen(['value1', 'value2'])   — valores que renderizam indicador de erro
 *
 * Unlisted values are rendered as "success" state (completed).
 */
class Status extends Field
{
    /** @var list<string> Values that trigger loading state */
    protected array $loadingWhen = [];

    /** @var list<string> Values that trigger failed state */
    protected array $failedWhen = [];

    /** {@inheritdoc} */
    public function type(): string
    {
        return 'status';
    }

    /** {@inheritdoc} */
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

    /** {@inheritdoc} */
    protected function defaultColumnWidth(): array
    {
        return ['width' => '120px'];
    }
}
