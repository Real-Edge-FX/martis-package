<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Scaffold a React override (TSX) into the consumer-extension
 * `overrides/` bucket (v1.9.0+ zero-config convention).
 *
 * The bundle's auto-discovery entry — published by `martis:install`
 * at `resources/js/martis-extensions/index.ts` — walks
 * `overrides/*.tsx` and registers each component against
 * `window.Martis.componentRegistry` under a fixed key map keyed by
 * filename (Sidebar → `layout:sidebar`, LoginPage → `auth:login`,
 * etc.). For `--type=generic` and `--type=field` the key is derived
 * directly from the filename's kebab-case form.
 *
 * **No boot.ts editing.** The command just drops the TSX. The next
 * `npm run build:extensions` (or deploy) wires it up.
 */
#[AsCommand(name: 'martis:component', aliases: ['martis:override'])]
class ComponentMakeCommand extends Command
{
    protected $signature = 'martis:component
        {name? : The component class name (e.g. StatusBadge). Optional when --type=complete-layout, ignored when --type maps to a fixed-name shell/auth piece.}
        {--type=generic : Component type: field | shell | sidebar | topbar | footer | complete-layout | login-page | register-page | forgot-password-page | reset-password-page | email-verify-notice-page | generic}
        {--force : Overwrite the file if it already exists}';

    protected $aliases = ['martis:override'];

    protected $description = 'Scaffold a React override component (TSX) into the consumer-extension overrides/ bucket';

    /**
     * Shell pieces: filename and registry key are fixed per type. The
     * auto-discovery entry's OVERRIDE_KEYS map looks up exactly these
     * filenames.
     *
     * @var array<string, array{filename: string, key: string, stub: string}>
     */
    private const SHELL_PIECES = [
        'shell' => ['filename' => 'Shell', 'key' => 'layout:shell', 'stub' => 'component-shell.tsx.stub'],
        'sidebar' => ['filename' => 'Sidebar', 'key' => 'layout:sidebar', 'stub' => 'component-sidebar.tsx.stub'],
        'topbar' => ['filename' => 'Topbar', 'key' => 'layout:topbar', 'stub' => 'component-topbar.tsx.stub'],
        'footer' => ['filename' => 'Footer', 'key' => 'layout:footer', 'stub' => 'component-footer.tsx.stub'],
    ];

    /**
     * Auth-page overrides. Same fixed-filename convention as
     * SHELL_PIECES. `--type=email-verify-notice-page` writes
     * `EmailVerifyNoticePage.tsx` under key `auth:email-verify`
     * (the index.ts mapping uses `auth:email-verify`, matching what
     * the SPA router resolves).
     *
     * @var array<string, array{filename: string, key: string, stub: string}>
     */
    private const AUTH_PAGES = [
        'login-page' => ['filename' => 'LoginPage', 'key' => 'auth:login', 'stub' => 'component-login-page.tsx.stub'],
        'register-page' => ['filename' => 'RegisterPage', 'key' => 'auth:register', 'stub' => 'component-register-page.tsx.stub'],
        'forgot-password-page' => ['filename' => 'ForgotPasswordPage', 'key' => 'auth:forgot-password', 'stub' => 'component-forgot-password-page.tsx.stub'],
        'reset-password-page' => ['filename' => 'ResetPasswordPage', 'key' => 'auth:reset-password', 'stub' => 'component-reset-password-page.tsx.stub'],
        'email-verify-notice-page' => ['filename' => 'EmailVerifyNoticePage', 'key' => 'auth:email-verify-notice', 'stub' => 'component-email-verify-notice-page.tsx.stub'],
    ];

    public function handle(): int
    {
        /** @var string|null $name */
        $name = $this->argument('name');
        /** @var string $type */
        $type = $this->option('type');

        $allowedTypes = [
            'field', 'shell', 'sidebar', 'topbar', 'footer', 'complete-layout', 'generic',
            'login-page', 'register-page', 'forgot-password-page', 'reset-password-page',
            'email-verify-notice-page',
        ];
        if (! in_array($type, $allowedTypes, true)) {
            $this->error("Invalid type '{$type}'. Allowed: ".implode(', ', $allowedTypes));

            return self::FAILURE;
        }

        if ($type === 'complete-layout') {
            return $this->generateCompleteLayout();
        }

        if (isset(self::SHELL_PIECES[$type]) || isset(self::AUTH_PAGES[$type])) {
            // Fixed-filename types: ignore the user's name argument
            // because the auto-discovery key map only looks at the
            // canonical filename (Sidebar.tsx → "layout:sidebar").
            return $this->generateFixedPiece($type);
        }

        if ($name === null || $name === '') {
            $this->error("Missing name. Usage: php artisan martis:component <Name> --type={$type}");

            return self::FAILURE;
        }

        return $this->generateUserNamed($type, $name);
    }

    /**
     * Generate a shell piece or auth page using its canonical
     * filename. The `name` argument is ignored — these slots have
     * exactly one component each.
     */
    protected function generateFixedPiece(string $type): int
    {
        $piece = self::SHELL_PIECES[$type] ?? self::AUTH_PAGES[$type];
        $filename = $piece['filename'];
        $relative = "resources/js/martis-extensions/overrides/{$filename}.tsx";
        $absolutePath = base_path($relative);

        if (! $this->confirmCollision($relative, $absolutePath)) {
            return self::FAILURE;
        }

        $this->writeStub($piece['stub'], $absolutePath, [
            '{{ class }}' => $filename,
            '{{ kebab }}' => Str::kebab($filename),
        ]);

        $this->info("Component created: {$relative}");
        $this->info("Auto-registered as '{$piece['key']}' on next `npm run build:extensions`.");
        $this->newLine();

        if (isset(self::SHELL_PIECES[$type])) {
            $this->line('This component plugs into the shell — no further wiring needed.');
            $this->line('Optional: pin it explicitly from PHP by setting');
            $this->line("  <comment>'layout' => ['components' => ['{$type}' => '{$piece['key']}']]</comment>");
        } else {
            $this->line('This component plugs into the auth router — no further wiring needed.');
            $this->line('Visiting the corresponding URL renders your override instead of the bundled page.');
            $this->line('Reference impl in: <comment>vendor/martis/martis/resources/js/pages/</comment>');
        }

        $this->newLine();
        $this->line('Build:');
        $this->line('  <comment>npm run build:extensions</comment>');

        return self::SUCCESS;
    }

    /**
     * Generate a user-named override (`--type=generic` or `--type=field`).
     * The component lives at `overrides/{ClassName}.tsx`; the
     * auto-discovery entry skips it from the OVERRIDE_KEYS lookup
     * (only fixed filenames live there) and emits a console warning
     * unless the consumer extends the keymap.
     *
     * v1.10.1+ auto-registers both `--type=field` (Display + Input
     * named exports under `{kebab}` / `{kebab}-input`) and
     * `--type=generic` (default export under `{kebab}`) — no manual
     * `OVERRIDE_KEYS` extension required.
     */
    protected function generateUserNamed(string $type, string $name): int
    {
        $className = Str::studly($name);
        $kebabName = Str::kebab($className);

        $relative = "resources/js/martis-extensions/overrides/{$className}.tsx";
        $absolutePath = base_path($relative);

        if (! $this->confirmCollision($relative, $absolutePath)) {
            return self::FAILURE;
        }

        $this->writeStub("component-{$type}.tsx.stub", $absolutePath, [
            '{{ class }}' => $className,
            '{{ kebab }}' => $kebabName,
        ], fallbackStub: 'component-generic.tsx.stub');

        $this->info("Component created: {$relative}");
        $this->newLine();

        if ($type === 'field') {
            $this->info("Auto-registered as '{$kebabName}' (display) + '{$kebabName}-input' (input) on next `npm run build:extensions`.");
            $this->newLine();
            $this->line('Usage in PHP (display — index/detail):');
            $this->line("  ->overrideIndex(new Override('{$kebabName}'))");
            $this->line("  ->overrideDetail(new Override('{$kebabName}'))");
            $this->newLine();
            $this->line('Usage in PHP (input — create/update):');
            $this->line("  ->overrideCreate(new Override('{$kebabName}-input'))");
            $this->line("  ->overrideUpdate(new Override('{$kebabName}-input'))");
            $this->newLine();
            $this->line('Tip: for a brand-new field type with matching PHP class, use');
            $this->line("  <comment>php artisan martis:field {$className}</comment>");
        } else {
            $this->info("Auto-registered as '{$kebabName}' on next `npm run build:extensions`.");
            $this->newLine();
            $this->line('Usage in PHP (any field type):');
            $this->line("  Text::make('field_name')->overrideIndex(new Override('{$kebabName}'))");
        }

        $this->newLine();
        $this->line('Build:');
        $this->line('  <comment>npm run build:extensions</comment>');

        return self::SUCCESS;
    }

    /**
     * Scaffold all four shell pieces at once. Keeps backwards
     * compatibility with the v1.8 behaviour but uses the new
     * `overrides/` bucket. The `name` argument, when supplied, used
     * to act as a class prefix; in v1.9 the canonical filenames
     * always win because the OVERRIDE_KEYS map is keyed on them.
     */
    protected function generateCompleteLayout(): int
    {
        $created = [];
        foreach (self::SHELL_PIECES as $type => $piece) {
            $relative = "resources/js/martis-extensions/overrides/{$piece['filename']}.tsx";
            $absolutePath = base_path($relative);

            if (file_exists($absolutePath) && $this->option('force') !== true) {
                $this->warn("Skipped {$relative} (already exists, use --force to overwrite)");

                continue;
            }

            $this->writeStub($piece['stub'], $absolutePath, [
                '{{ class }}' => $piece['filename'],
                '{{ kebab }}' => Str::kebab($piece['filename']),
            ]);

            $created[] = [$piece['filename'], $piece['key'], $relative];
        }

        if ($created === []) {
            $this->warn('No files were created.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Complete layout scaffolded:');
        foreach ($created as [$class, $key, $path]) {
            $this->line("  <fg=green>✓</> {$class}  →  '{$key}'  ({$path})");
        }

        $this->newLine();
        $this->line('Build:');
        $this->line('  <comment>npm run build:extensions</comment>');

        return self::SUCCESS;
    }

    /**
     * Detect collisions before writing. Honours `--force` and aborts
     * non-interactive shells without prompting.
     */
    protected function confirmCollision(string $relative, string $absolutePath): bool
    {
        if (! file_exists($absolutePath)) {
            return true;
        }
        if ($this->option('force') === true) {
            return true;
        }
        if (! $this->input->isInteractive() || $this->laravel->runningUnitTests()) {
            $this->error("Component already exists: {$relative}  (re-run with --force to overwrite)");

            return false;
        }

        return $this->confirm("{$relative} already exists. Overwrite?", false);
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function writeStub(string $stubName, string $absolutePath, array $replacements, ?string $fallbackStub = null): void
    {
        $stubPath = StubResolver::path($stubName);
        if (! file_exists($stubPath) && $fallbackStub !== null) {
            $stubPath = StubResolver::path($fallbackStub);
        }

        $stub = (string) file_get_contents($stubPath);
        $content = strtr($stub, $replacements);

        @mkdir(dirname($absolutePath), 0755, true);
        file_put_contents($absolutePath, $content);
    }
}
