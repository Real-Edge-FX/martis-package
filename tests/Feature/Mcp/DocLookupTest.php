<?php

declare(strict_types=1);

use Martis\Mcp\DocLookup;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/martis-doc-lookup-'.uniqid();
    mkdir($this->dir, 0755, true);
    file_put_contents($this->dir.'/dashboards.md', "# Dashboards\n\nDashboards are top-level admin pages built from cards and metrics.\n\nThey support gates and icons.\n");
    file_put_contents($this->dir.'/gates.md', "# Gates\n\nSoft gates wrap entities with badges and lock modals.\n");
    file_put_contents($this->dir.'/fields.md', "# Fields\n\nField types include Text, Code, Boolean, Select, MultiSelect, BelongsTo and BelongsToMany.\n");
});

afterEach(function () {
    if (isset($this->dir) && is_dir($this->dir)) {
        rmtree($this->dir);
    }
});

it('lists every markdown page sorted by slug with one-liners', function () {
    $rows = (new DocLookup($this->dir))->list();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]['slug'])->toBe('dashboards')
        ->and($rows[0]['one_liner'])->toContain('top-level admin pages');
});

it('reads a single page by slug', function () {
    $content = (new DocLookup($this->dir))->read('gates');
    expect($content)->toContain('Soft gates wrap entities');
});

it('returns null for unknown or unsafe slugs', function () {
    $lookup = new DocLookup($this->dir);
    expect($lookup->read('does-not-exist'))->toBeNull();
    expect($lookup->read('../etc/passwd'))->toBeNull();
});

it('searches across pages and ranks by frequency', function () {
    $rows = (new DocLookup($this->dir))->search('gates', 5);
    expect($rows[0]['slug'])->toBe('gates')
        ->and($rows[0]['snippet'])->toContain('Soft gates');
});

it('returns empty results when the query is empty', function () {
    expect((new DocLookup($this->dir))->search(''))->toBe([]);
});
