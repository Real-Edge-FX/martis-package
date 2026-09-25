<?php

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Prompt;
use Martis\Console\AgentsCommand;
use Martis\Console\CardMakeCommand;
use Martis\Console\ComponentMakeCommand;
use Martis\Console\FieldMakeCommand;
use Martis\Console\InvitationsScaffoldCommand;
use Martis\Console\RolesScaffoldCommand;
use Martis\Console\SsoMakeCommand;
use Martis\Console\ThemeMakeCommand;
use Martis\Console\ToolMakeCommand;
use Martis\Support\AgentDetector;
use Martis\Support\ThemeFiles;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Every Martis command asks its questions only on a terminal
 * (AsksOnlyOnATerminal). They checked Symfony's isInteractive() alone,
 * which a pipe without --no-interaction passes: `yes | php artisan
 * martis:card Foo` answered "y" to "already exists. Overwrite?" (default
 * no) and rewrote the React component the developer had customised, and
 * `docker compose exec -T` waited forever. Without a TTY each command now
 * does what it does with --no-interaction: warn and leave the file alone
 * unless --force, or run the migrations.
 */

trait FakeTerminal
{
    public bool $tty = false;

    /** @var list<string> */
    public array $asked = [];

    /** @var list<string> */
    public array $called = [];

    protected function insideUnitTests(): bool
    {
        return false;
    }

    protected function stdinIsTty(): bool
    {
        return $this->tty;
    }

    public function confirm($question, $default = false)
    {
        $this->asked[] = $question;

        return false;
    }

    public function call($command, array $arguments = [])
    {
        $this->called[] = $command;

        return 0;
    }
}

/**
 * @template T of Command
 *
 * @param  T  $command
 * @param  array<string, mixed>  $arguments
 * @return T
 */
function onTerminal(Command $command, bool $tty, array $arguments = []): Command
{
    $command->tty = $tty;
    $command->setLaravel(app());
    // The global --no-interaction option, which the application adds to a
    // command it runs, so a guard that reads it still resolves.
    $definition = $command->getDefinition();
    if (! $definition->hasOption('no-interaction')) {
        $definition->addOption(new InputOption('no-interaction', 'n'));
    }
    $input = new ArrayInput($arguments, $definition);
    $input->setInteractive(true);
    (fn () => $this->input = $input)->call($command);
    (function () use ($input) {
        $this->output = new OutputStyle($input, new BufferedOutput);
        $this->components = new Factory($this->output);
    })->call($command);

    return $command;
}

/** Write `$contents` at `$path` for the test and remove it after, or put back what was there. */
function withExistingFile(string $path, callable $test): void
{
    $previous = is_file($path) ? (string) file_get_contents($path) : null;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "// customised by the developer\n");
    try {
        $test();
        expect(file_get_contents($path))->toBe("// customised by the developer\n");
    } finally {
        $previous === null ? @unlink($path) : file_put_contents($path, $previous);
    }
}

/**
 * [command factory, arguments, path of the file it would overwrite, run the guarded step, the question]
 *
 * @return array<string, array{0: Closure, 1: array<string, mixed>, 2: string, 3: Closure, 4: string}>
 */
function overwritePrompts(): array
{
    return [
        'martis:card' => [
            fn () => new class(app(Filesystem::class)) extends CardMakeCommand
            {
                use FakeTerminal;
            },
            ['name' => 'AskedCard'],
            'resources/js/martis-extensions/cards/AskedCard.tsx',
            fn () => $this->generateReactComponent('AskedCard', 'asked-card'),
            'resources/js/martis-extensions/cards/AskedCard.tsx already exists. Overwrite?',
        ],
        'martis:field' => [
            fn () => new class(app(Filesystem::class)) extends FieldMakeCommand
            {
                use FakeTerminal;
            },
            ['name' => 'AskedField'],
            'resources/js/martis-extensions/fields/AskedField.tsx',
            fn () => $this->generateTsxComponent('AskedField', 'AskedField', 'asked-field'),
            'resources/js/martis-extensions/fields/AskedField.tsx already exists. Overwrite?',
        ],
        'martis:component' => [
            fn () => new class extends ComponentMakeCommand
            {
                use FakeTerminal;
            },
            [],
            'resources/js/martis-extensions/overrides/AskedComponent.tsx',
            fn () => $this->confirmCollision('resources/js/martis-extensions/overrides/AskedComponent.tsx', base_path('resources/js/martis-extensions/overrides/AskedComponent.tsx')),
            'resources/js/martis-extensions/overrides/AskedComponent.tsx already exists. Overwrite?',
        ],
        'martis:tool' => [
            fn () => new class(app(Filesystem::class)) extends ToolMakeCommand
            {
                use FakeTerminal;
            },
            ['name' => 'AskedTool'],
            'resources/js/martis-extensions/tools/AskedTool.tsx',
            fn () => $this->confirmCollisionForExtension(base_path('resources/js/martis-extensions/tools/AskedTool.tsx'), 'tools/asked-tool', 'resources/js/martis-extensions/tools/AskedTool.tsx'),
            'Overwrite the existing file(s) and continue?',
        ],
    ];
}

it('neither asks nor overwrites an existing file without a TTY', function (string $name) {
    [$make, $arguments, $relative, $step] = overwritePrompts()[$name];
    $command = onTerminal($make(), false, $arguments);

    withExistingFile(base_path($relative), function () use ($command, $step) {
        expect($step->call($command))->toBeFalse()
            ->and($command->asked)->toBe([]);
    });
})->with(array_keys(overwritePrompts()));

it('asks before it overwrites an existing file on a TTY (control)', function (string $name) {
    [$make, $arguments, $relative, $step, $question] = overwritePrompts()[$name];
    $command = onTerminal($make(), true, $arguments);

    withExistingFile(base_path($relative), function () use ($command, $step, $question) {
        // Answered "no": nothing is written.
        expect($step->call($command))->toBeFalse()
            ->and($command->asked)->toBe([$question]);
    });
})->with(array_keys(overwritePrompts()));

it('leaves an existing theme alone without asking when there is no TTY, and asks on one', function (bool $tty, array $asked) {
    $command = onTerminal(new class extends ThemeMakeCommand
    {
        use FakeTerminal;
    }, $tty, ['name' => 'asked-theme']);

    withExistingFile(ThemeFiles::sourcePath('asked-theme'), function () use ($command, $asked) {
        expect($command->handle())->toBe(Command::SUCCESS)
            ->and($command->asked)->toBe($asked);
    });
})->with([
    'no TTY' => [false, []],
    'TTY' => [true, ["Theme 'asked-theme.css' already exists. Overwrite?"]],
]);

/** @return array<string, array{0: Closure, 1: array<string, mixed>}> */
function migrationPrompts(): array
{
    return [
        'martis:invitations' => [fn () => new class extends InvitationsScaffoldCommand
        {
            use FakeTerminal;
        }, []],
        'martis:roles' => [fn () => new class extends RolesScaffoldCommand
        {
            use FakeTerminal;
        }, []],
        'martis:sso' => [fn () => new class extends SsoMakeCommand
        {
            use FakeTerminal;
        }, ['provider' => 'azure']],
    ];
}

it('runs the migrations without asking when there is no TTY, as with --no-interaction', function (string $name) {
    [$make, $arguments] = migrationPrompts()[$name];
    $command = onTerminal($make(), false, $arguments);

    (fn () => $this->runMigrations())->call($command);

    expect($command->asked)->toBe([])->and($command->called)->toBe(['migrate']);
})->with(array_keys(migrationPrompts()));

it('asks before it runs the migrations on a TTY (control)', function (string $name) {
    [$make, $arguments] = migrationPrompts()[$name];
    $command = onTerminal($make(), true, $arguments);

    (fn () => $this->runMigrations())->call($command);

    // Answered "no": the migrations are skipped.
    expect($command->asked)->toBe(['Run pending migrations now?'])->and($command->called)->toBe([]);
})->with(array_keys(migrationPrompts()));

it('makes every console command that asks go through AsksOnlyOnATerminal', function () {
    $root = dirname(__DIR__, 2).'/src/Console';
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $asks = preg_match('/\$this->(ask|secret|confirm|choice|anticipate)\(|Laravel\\\\Prompts\\\\/', $source) === 1;
        if ($asks && ! str_contains($source, 'use AsksOnlyOnATerminal;') && ! str_contains($source, 'trait AsksOnlyOnATerminal')) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($offenders);

    expect($offenders)->toBe([]);
});

/*
 * A generator whose file exists leaves it alone with an error line and
 * exits 0, as Laravel's GeneratorCommand does (`{type} already exists.`,
 * `handle()` returns false, which `Command::execute()` casts to 0) and so
 * Nova's generators, which extend it. martis:component and martis:theme
 * exited 1, martis:tool printed "Aborting" and exited 0.
 */
it('leaves an existing generated file alone with an "already exists" error and exit 0', function (string $command, array $arguments, string $relative, ?string $phpClass, string $message) {
    $created = $phpClass === null ? null : base_path($phpClass);
    $existedBefore = $created !== null && is_file($created);
    try {
        withExistingFile(base_path($relative), function () use ($command, $arguments, $message) {
            $this->artisan($command, $arguments)
                ->expectsOutputToContain($message)
                ->assertExitCode(0);
        });
    } finally {
        if ($created !== null && ! $existedBefore) {
            @unlink($created);
        }
    }
})->with([
    'martis:card' => ['martis:card', ['name' => 'ExitCard'], 'resources/js/martis-extensions/cards/ExitCard.tsx', 'app/Martis/Cards/ExitCard.php', 'already exists'],
    'martis:field' => ['martis:field', ['name' => 'ExitField'], 'resources/js/martis-extensions/fields/Exit.tsx', 'app/Martis/Fields/ExitField.php', 'already exists'],
    'martis:tool' => ['martis:tool', ['name' => 'ExitTool', '--with-component' => true], 'resources/js/martis-extensions/tools/ExitTool.tsx', 'app/Martis/Tools/ExitTool.php', 'already exists'],
    'martis:component' => ['martis:component', ['name' => 'ExitComponent', '--type' => 'generic'], 'resources/js/martis-extensions/overrides/ExitComponent.tsx', null, 'already exists'],
]);

/*
 * martis:agents asks through Laravel Prompts, which ask nothing without a
 * TTY but answer their own defaults: a pipe wired the MCP server (default
 * yes) where --no-interaction does not. Without a terminal it now behaves
 * as with --no-interaction.
 */
it('leaves the MCP server unwired without asking when there is no TTY, as with --no-interaction', function () {
    $command = onTerminal(new class extends AgentsCommand
    {
        use FakeTerminal;
    }, false);
    $profiles = array_values(array_filter(
        (new AgentDetector(base_path()))->profiles(),
        static fn ($profile): bool => $profile->supportsMcp(),
    ));
    expect($profiles)->not->toBe([]);

    // Laravel Prompts' confirm() is a function: record it through the
    // fallback Laravel uses in the tests, and put the original back.
    $fallbacks = new ReflectionProperty(Prompt::class, 'fallbacks');
    $saved = $fallbacks->getValue();
    Prompt::fallbackWhen(true);
    Prompt::interactive(true);
    $prompted = [];
    ConfirmPrompt::fallbackUsing(function ($prompt) use (&$prompted) {
        $prompted[] = $prompt->label;

        return $prompt->default;
    });
    try {
        // A private method of the parent: bind to its scope.
        $choice = Closure::bind(fn () => $this->resolveMcpChoice($profiles), $command, AgentsCommand::class);

        expect($choice())->toBeFalse()
            ->and($prompted)->toBe([]);
    } finally {
        $fallbacks->setValue(null, $saved);
    }
});
