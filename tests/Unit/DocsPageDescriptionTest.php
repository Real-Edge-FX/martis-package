<?php

use Martis\Support\DocDescription;

/*
 * The docs site takes each page's description, its search excerpt and meta
 * description, from the first paragraph after the title that is not a
 * blockquote, heading, list item, code fence or HTML, as plain text, and
 * keeps its first 280 characters (deriveTitleAndDescription() in martis-docs
 * scripts/sync-docs.mjs; DocDescription mirrors it). A table, a line of
 * navigation or a paragraph the site cuts mid-word makes a poor description,
 * and so does a first sentence that is a slogan (under 60 characters) or
 * runs past the 160 a search result shows.
 */

/** The text up to the first `.`, `!` or `?` followed by a space, or all of it. */
function docFirstSentence(string $description): string
{
    return preg_split('/(?<=[.!?])\s+/u', $description, 2)[0];
}

it('takes a description\'s first sentence up to the first full stop followed by a space', function () {
    expect(docFirstSentence('Martis reads `config/martis.php`. Publish it first.'))->toBe('Martis reads `config/martis.php`.')
        ->and(docFirstSentence('One sentence without a break'))->toBe('One sentence without a break')
        ->and(docFirstSentence('Is it here? Yes.'))->toBe('Is it here?');
});

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
        $markdown = (string) file_get_contents($page);
        $name = substr($page, strlen($root) + 1);
        if (preg_match('/^#\s+.+$/m', $markdown) !== 1) {
            $weak[$name] = 'no title';

            continue;
        }

        $description = DocDescription::fromMarkdown($markdown);
        $length = mb_strlen($description);

        // The site writes the description into a double-quoted YAML string
        // and escapes only the quotes: a backslash there (`Martis\Foo`) is an
        // invalid escape and breaks the sync.
        if (str_contains($description, '\\')) {
            $weak[$name] = 'backslash: '.mb_substr($description, 0, 80);

            continue;
        }

        if ($length < 60 || $length > DocDescription::MAX_LENGTH || str_starts_with($description, '|') || preg_match('/^(This (document|page|guide)|For |See )/', $description) === 1) {
            $weak[$name] = "{$length} chars: ".mb_substr($description, 0, 80);

            continue;
        }

        // Search results and link previews cut a description near 160
        // characters, so its first sentence must say what the page is about
        // within that, and be more than a slogan.
        $sentence = docFirstSentence($description);
        $sentenceLength = mb_strlen($sentence);
        if ($sentenceLength < 60 || $sentenceLength > 160) {
            $weak[$name] = "first sentence {$sentenceLength} chars: ".mb_substr($sentence, 0, 80);
        }
    }

    expect($weak)->toBe([]);
});
