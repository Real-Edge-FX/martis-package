<?php

namespace Martis\Menu;

use Martis\Resource;

class Menu
{
    /** @var list<MenuSection> */
    protected array $sections = [];

    /**
     * @param  list<MenuSection|MenuItem|class-string<resource>>  $sections
     */
    public function __construct(array $sections = [])
    {
        $this->sections = $this->normalizeSections($sections);
    }

    /**
     * @param  list<MenuSection|MenuItem|class-string<resource>>  $sections
     */
    public static function make(array $sections = []): self
    {
        return new self($sections);
    }

    /**
     * @param  list<MenuSection|MenuItem|class-string<resource>>  $sections
     */
    public function sections(array $sections): self
    {
        $this->sections = $this->normalizeSections($sections);

        return $this;
    }

    public function append(MenuSection|MenuItem|string $section): self
    {
        $this->sections[] = $this->normalizeSection($section);

        return $this;
    }

    public function prepend(MenuSection|MenuItem|string $section): self
    {
        array_unshift($this->sections, $this->normalizeSection($section));

        return $this;
    }

    /**
     * @return list<MenuSection>
     */
    public function all(): array
    {
        return $this->sections;
    }

    /**
     * @param  list<MenuSection|MenuItem|class-string<resource>>  $sections
     * @return list<MenuSection>
     */
    protected function normalizeSections(array $sections): array
    {
        return array_values(array_map(
            fn (MenuSection|MenuItem|string $section): MenuSection => $this->normalizeSection($section),
            $sections
        ));
    }

    protected function normalizeSection(MenuSection|MenuItem|string $section): MenuSection
    {
        if ($section instanceof MenuSection) {
            return $section;
        }

        return MenuSection::make(null, [$section]);
    }
}
