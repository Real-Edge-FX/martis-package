<?php

namespace Martis\Support;

/**
 * The description of a docs page: the first paragraph after its title that
 * is not a blockquote, heading, list item, code fence or HTML, as plain text.
 *
 * The docs site derives a page's description (its search excerpt and meta
 * description) this way (`deriveTitleAndDescription()` in martis-docs
 * `scripts/sync-docs.mjs`) and keeps its first MAX_LENGTH characters; the
 * docs MCP server's `martis_doc_list` tool uses it too, so both describe a page alike.
 */
final class DocDescription
{
    public const MAX_LENGTH = 280;

    public static function fromMarkdown(string $markdown): string
    {
        if (preg_match('/^#\s+.+$/m', $markdown, $title, PREG_OFFSET_CAPTURE) === 1) {
            $markdown = substr($markdown, $title[0][1] + strlen($title[0][0]));
        }

        foreach (preg_split('/\n\s*\n/', ltrim($markdown)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '' || preg_match('/^[>#\-*]/', $paragraph) === 1 || str_starts_with($paragraph, '```') || str_starts_with($paragraph, '<')) {
                continue;
            }

            return self::plainText(str_replace("\n", ' ', $paragraph));
        }

        return '';
    }

    /** Links, bold and inline code reduced to their text, as the site does. */
    public static function plainText(string $markdown): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $markdown);
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/', '$2', $text);

        return trim((string) preg_replace('/`([^`]*)`/', '$1', $text));
    }
}
