<?php

/*
 * The docs site (martis-docs) takes each page's description, its search
 * excerpt and meta description, from the first paragraph after the title
 * that is not a blockquote, heading, list item, code fence or HTML
 * (deriveTitleAndDescription() in its scripts/sync-docs.mjs). A table or a
 * line of navigation there makes a poor description.
 */

it('opens every docs page with a paragraph that describes it', function () {
    $pages = glob(dirname(__DIR__, 2).'/docs/*.md') ?: [];
    expect($pages)->not->toBeEmpty();

    $weak = [];
    foreach ($pages as $page) {
        $markdown = (string) file_get_contents($page);
        if (preg_match('/^#\s+.+$/m', $markdown, $title, PREG_OFFSET_CAPTURE) !== 1) {
            $weak[basename($page)] = 'no title';

            continue;
        }

        $description = '';
        $afterTitle = ltrim(substr($markdown, $title[0][1] + strlen($title[0][0])));
        foreach (preg_split('/\n\s*\n/', $afterTitle) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '' || preg_match('/^[>#\-*]/', $paragraph) === 1 || str_starts_with($paragraph, '```') || str_starts_with($paragraph, '<')) {
                continue;
            }
            $description = $paragraph;

            break;
        }

        if (strlen($description) < 60 || str_starts_with($description, '|') || preg_match('/^(This (document|page|guide)|For |See )/', $description) === 1) {
            $weak[basename($page)] = substr($description, 0, 80);
        }
    }

    expect($weak)->toBe([]);
});
