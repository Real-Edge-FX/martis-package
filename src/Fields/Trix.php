<?php

namespace Martis\Fields;

use Martis\Enums\ClickBehavior;
use Martis\Enums\ToolbarSize;

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

    protected ?ToolbarSize $toolbarSize = null;

    protected ClickBehavior $imageClickBehavior = ClickBehavior::Modal;

    protected ClickBehavior $linkClickBehavior = ClickBehavior::SamePage;

    public function type(): string
    {
        return 'trix';
    }

    public static function make(string $attribute, ?string $label = null): static
    {
        return parent::make($attribute, $label)->hideFromIndex();
    }

    public function alwaysShow(): static
    {
        $this->alwaysShow = true;

        return $this;
    }

    public function withFiles(string $disk = 'public'): static
    {
        $this->withFilesDisk = $disk;

        return $this;
    }

    public function toolbarSize(ToolbarSize $size): static
    {
        $this->toolbarSize = $size;

        return $this;
    }

    public function imageClickBehavior(ClickBehavior $behavior): static
    {
        $this->imageClickBehavior = $behavior;

        return $this;
    }

    public function linkClickBehavior(ClickBehavior $behavior): static
    {
        $this->linkClickBehavior = $behavior;

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
            'toolbarSize' => $this->toolbarSize?->value,
            'imageClickBehavior' => $this->imageClickBehavior->value,
            'linkClickBehavior' => $this->linkClickBehavior->value,
        ], fn ($v) => $v !== null && $v !== false);
    }
}
