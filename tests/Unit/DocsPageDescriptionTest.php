<?php

use Martis\Support\DocDescription;

/*
 * The docs site takes each page's description, its search excerpt and meta
 * description, from the first paragraph after the title that is not a
 * blockquote, heading, list item, code fence or HTML, as plain text, and
 * keeps its first 280 characters (deriveTitleAndDescription() in martis-docs
 * scripts/sync-docs.mjs; DocDescription mirrors it). A table, a line of
 * navigation or a paragraph the site cuts mid-word makes a poor description.
 */

it('derives a description the way the docs site does', function () {
    $markdown = "# Title\n\n> A summary line the site skips.\n\n## A heading\n\n- a list item\n\nThe **first** paragraph, with [a link](https://example.com)\nand `inline code`.\n\nThe second paragraph.";

    expect(DocDescription::fromMarkdown($markdown))->toBe('The first paragraph, with a link and inline code.');
});

it('opens every docs page with a paragraph that describes it', function () {
    $root = dirname(__DIR__, 2).'/docs';
    $pages = array_merge(glob($root.'/*.md') ?: [], glob($root.'/*/*.md') ?: []);
    expect($pages)->not->toBeEmpty();

    $weak = [];
    foreach ($pages as $page) {
        $description = DocDescription::fromMarkdown((string) file_get_contents($page));
        $length = mb_strlen($description);

        if ($length < 60 || $length > DocDescription::MAX_LENGTH || str_starts_with($description, '|') || preg_match('/^(This (document|page|guide)|For |See )/', $description) === 1) {
            $weak[substr($page, strlen($root) + 1)] = "{$length} chars: ".mb_substr($description, 0, 80);
        }
    }

    expect($weak)->toBe([]);
});
