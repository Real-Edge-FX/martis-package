<?php

namespace Martis\Fields;

/**
 * Trix rich-text editor field — HTML-based WYSIWYG editing.
 *
 * Paridade com Laravel Nova v5: Trix field.
 * Referência: https://nova.laravel.com/docs/v5/resources/fields
 *
 * Contextos:
 *  - index: oculto por padrão (HTML longo não adequado)
 *  - detail: conteúdo oculto atrás de "Show Content" por padrão;
 *            alwaysShow() expande automaticamente
 *  - create: editor Trix (rich text HTML)
 *  - update: editor Trix (rich text HTML)
 *
 * Armazena HTML bruto no banco.
 *
 * Divergências intencionais do Nova:
 *  - withFiles() registra o disk mas o upload é gerenciado pelo endpoint
 *    genérico de attachments do Martis, sem tabelas auxiliares do Nova.
 */
class Trix extends Field
{
    protected bool $alwaysShow = false;

    protected ?string $withFilesDisk = null;

    protected ?string $toolbarSize = null;

    public function type(): string
    {
        return 'trix';
    }

    /**
     * Override make() to default to hidden on index.
     */
    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    /**
     * Always show the HTML content on detail view,
     * instead of hiding it behind a "Show Content" toggle.
     */
    public function alwaysShow(): static
    {
        $this->alwaysShow = true;

        return $this;
    }

    /**
     * Enable file uploads in the Trix editor.
     *
     * @param  string  $disk  The filesystem disk to store uploads on.
     */
    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    /**
     * Set toolbar button size: 'sm', 'md' (default), or 'lg'.
     *
     * Usage: Trix::make('content')->toolbarSize('sm')
     */
    public function toolbarSize(string $size): static
    {
        $this->toolbarSize = $size;

        return $this;
    }

    public function isAlwaysShow(): bool
    {
        return $this->alwaysShow;
    }

    public function getWithFilesDisk(): ?string
    {
        return $this->withFilesDisk;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_filter([
            'alwaysShow' => $this->alwaysShow,
            'withFiles' => $this->withFilesDisk,
            'toolbarSize' => $this->toolbarSize,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
