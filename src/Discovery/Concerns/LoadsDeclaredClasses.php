<?php

namespace Martis\Discovery\Concerns;

/**
 * Loads a discovered file only when it declares the class its path implies.
 *
 * Discovery walks every `*.php` under the configured root. A file that is
 * not a class — a routes file, a helper file, a config fragment — must not
 * be executed by discovery: its top-level statements would run outside any
 * route group or service-provider context. A `routes/tool.php` sitting
 * under `app/Martis/Tools/` used to register its routes a second time at
 * the application root, with no `web`, no `martis.auth` and no tool gate.
 *
 * Both ways a file could be executed are closed: the explicit
 * `require_once`, and `class_exists($class)` with autoloading, which under
 * PSR-4 maps `App\Martis\Tools\routes\web` straight back to
 * `app/Martis/Tools/routes/web.php` and includes it.
 */
trait LoadsDeclaredClasses
{
    /**
     * Whether the file declares a class/interface/trait/enum whose short
     * name matches the one derived from its path.
     *
     * Token-based, so the word `class` in a comment, a string or a
     * `::class` constant does not count.
     */
    protected function declaresClass(string $filePath, string $class): bool
    {
        $contents = @file_get_contents($filePath);

        if ($contents === false || $contents === '') {
            return false;
        }

        $position = strrpos($class, '\\');
        $shortName = $position === false ? $class : substr($class, $position + 1);

        // Cheap reject before tokenizing: the declared name has to appear
        // somewhere in the file for the declaration to be there. Discovery
        // runs on every boot, so the common case (a file that declares
        // nothing, which is the one that must not be executed) costs one
        // read and one regular expression, never a token pass.
        if (! str_contains($contents, $shortName)) {
            return false;
        }

        $quoted = preg_quote($shortName, '/');

        if (preg_match('/\b(?:class|interface|trait|enum)\s+'.$quoted.'\b/i', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        // Tokenize only up to the end of that candidate declaration. What
        // decides whether it is real — a comment, a string, a heredoc, the
        // namespace, the brace depth — is all before it, and a class body
        // is most of a file. Discovery runs on every boot, so this keeps
        // the pass proportional to the header rather than to the file.
        $contents = substr($contents, 0, (int) $match[0][1] + strlen((string) $match[0][0]));

        $tokens = @token_get_all($contents);
        $declarations = [T_CLASS, T_INTERFACE, T_TRAIT];

        if (defined('T_ENUM')) {
            $declarations[] = T_ENUM;
        }

        $count = count($tokens);
        $namespace = '';
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // The file's namespace, so `namespace Elsewhere; class web {}`
            // in `routes/web.php` does not pass for `App\…\routes\web`.
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($forward = $i + 1; $forward < $count; $forward++) {
                    $next = $tokens[$forward];

                    if ($next === ';' || $next === '{') {
                        // A braced namespace keeps its contents at the
                        // depth a declaration is expected to sit at.
                        $i = $forward;
                        break;
                    }

                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    if (is_array($next)) {
                        $namespace .= $next[1];
                    }
                }

                continue;
            }

            // Only a declaration at the top level counts: one inside a
            // function or an `if` block runs when that code runs, and this
            // file is not meant to be executed at all.
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;

                continue;
            }

            if ($token === '}') {
                $depth--;

                continue;
            }

            if (! is_array($token) || ! in_array($token[0], $declarations, true)) {
                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if (strcasecmp(trim($namespace, '\\'), trim((string) substr($class, 0, max(0, (int) strrpos($class, '\\'))), '\\')) !== 0) {
                continue;
            }

            // `Foo::class` and `new class {}` are not declarations.
            for ($back = $i - 1; $back >= 0; $back--) {
                $previous = $tokens[$back];
                if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                    continue 2;
                }
                break;
            }

            for ($forward = $i + 1; $forward < $count; $forward++) {
                $next = $tokens[$forward];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    if (strcasecmp($next[1], $shortName) === 0) {
                        return true;
                    }
                }
                break;
            }
        }

        return false;
    }

    /**
     * Make the class of a discovered file available, without executing a
     * file that does not declare it.
     */
    protected function loadDeclaredClass(string $filePath, string $class): bool
    {
        // Already loaded (manually registered, or required by another file):
        // the `false` keeps the autoloader out of it.
        if (class_exists($class, false)) {
            return true;
        }

        if (! $this->declaresClass($filePath, $class)) {
            return false;
        }

        if (! class_exists($class)) {
            require_once $filePath;
        }

        return class_exists($class, false);
    }
}
