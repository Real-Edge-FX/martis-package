<?php

namespace Martis\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * An upload that a browser runs when it is opened.
 *
 * A `File` stores what it receives on a disk the web server usually serves
 * from the application's own origin (the `public` disk, under `/storage`). An
 * HTML, SVG or XML document, or a script, served from there runs in the
 * application's origin with the session of whoever opens the link: a panel
 * user who may upload plants the page an administrator later opens. So the
 * field refuses active content unless the developer opts in (see
 * `File::allowActiveContent()`, or list the type in `acceptedTypes()`).
 *
 * Two things decide what an upload is, and both are checked:
 *
 *  - the extension the file is stored with: the one the field gives it
 *    (`hashName()` takes it from the content's MIME type, `preserveOriginalName()`
 *    keeps the client's own), every segment of it, so `evil.php.jpg` is
 *    caught as well as `evil.php`;
 *  - the MIME type the server reads from the content (never the one the client
 *    claims), so an HTML document that carries an image name is caught too.
 *
 * A type listed in the field's `acceptedTypes()` is an explicit opt-in: the
 * extensions it names, and the MIME types those extensions belong to, pass.
 */
final class NoActiveContent implements ValidationRule
{
    /**
     * The extensions a browser or the web server runs: markup that carries
     * script (HTML, SVG, XML and stylesheets-by-XSL), script files, and the
     * server-side script and configuration files of the common web servers.
     *
     * @var list<string>
     */
    public const EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'shtm', 'mht', 'mhtml', 'hta',
        'svg', 'svgz', 'xml', 'xsl', 'xslt',
        // Other XML documents a browser renders (and may run script in), by
        // the extension a web server's mime.types maps to an XML type.
        'rss', 'atom', 'rdf', 'rdfs', 'owl', 'xsd', 'dtd', 'xbl', 'xul', 'mathml', 'mml', 'wsdl', 'wadl', 'xspf', 'xaml', 'smil', 'smi',
        // Flash.
        'swf',
        'js', 'mjs', 'cjs',
        'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pgif',
        'asp', 'aspx', 'ashx', 'asmx', 'cer', 'jsp', 'jspx', 'jsw', 'jsv', 'jspf', 'cfm', 'cgi',
        'htaccess', 'htpasswd',
    ];

    /**
     * The MIME types the server reads from a file's content that a browser
     * runs. Every `+xml` type joins them (see `isActiveMime()`): a browser
     * renders it as an XML document, which can carry script.
     *
     * @var list<string>
     */
    public const MIME_TYPES = [
        'text/html', 'application/xhtml+xml',
        'image/svg+xml', 'text/xml', 'application/xml', 'text/xsl',
        'text/javascript', 'application/javascript', 'application/x-javascript', 'application/ecmascript', 'text/ecmascript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/x-httpd-php-source',
    ];

    /**
     * @param  list<string>  $acceptedTypes  The extensions the field accepts (`acceptedTypes()`); an active one listed there is let through.
     * @param  (Closure(UploadedFile): string)|null  $storedName  The name the field stores an upload under, to check its extension.
     */
    public function __construct(
        private readonly array $acceptedTypes = [],
        private readonly ?Closure $storedName = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $name = $this->storedName !== null ? ($this->storedName)($value) : null;

        if (self::refuses($value, $this->acceptedTypes, $name)) {
            $fail('martis::validation.active_content')->translate();
        }
    }

    /**
     * Whether an upload is active content its field does not accept.
     *
     * @param  list<string>  $acceptedTypes
     * @param  string|null  $storedName  The name the upload is stored under, when the client's extension is kept.
     */
    public static function refuses(UploadedFile $file, array $acceptedTypes, ?string $storedName): bool
    {
        $optedIn = array_map(
            static fn (mixed $type): string => strtolower(ltrim(trim((string) $type), '.')),
            $acceptedTypes,
        );

        if ($storedName !== null) {
            // Every segment after the base name: a double extension counts.
            $segments = explode('.', strtolower(basename($storedName)));
            array_shift($segments);

            foreach ($segments as $segment) {
                $extension = trim($segment);

                if (in_array($extension, self::EXTENSIONS, true) && ! in_array($extension, $optedIn, true)) {
                    return true;
                }
            }
        }

        $mime = strtolower(trim((string) $file->getMimeType()));

        return $mime !== '' && self::isActiveMime($mime) && ! self::mimeOptedIn($mime, $optedIn);
    }

    /**
     * The message of a refused upload, for a caller that fails outside a
     * validator (the fill of a field whose upload skipped validation).
     */
    public static function message(string $label): string
    {
        try {
            if (function_exists('app') && app()->bound('translator')) {
                $translated = __('martis::validation.active_content', ['attribute' => $label]);

                if (is_string($translated) && $translated !== 'martis::validation.active_content') {
                    return $translated;
                }
            }
        } catch (\Throwable) {
            // No translator (a raw unit test): the English text below.
        }

        return "The {$label} must not be an HTML, SVG, XML or script file.";
    }

    private static function isActiveMime(string $mime): bool
    {
        return in_array($mime, self::MIME_TYPES, true) || str_ends_with($mime, '+xml');
    }

    /**
     * Whether the field accepts a type that this MIME type belongs to.
     *
     * @param  list<string>  $optedIn
     */
    private static function mimeOptedIn(string $mime, array $optedIn): bool
    {
        return array_intersect(MimeTypes::getDefault()->getExtensions($mime), $optedIn) !== [];
    }
}
