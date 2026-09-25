<?php

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Martis\Console\CardMakeCommand;
use Martis\Console\ComponentMakeCommand;
use Martis\Console\FieldMakeCommand;
use Martis\Console\InvitationsScaffoldCommand;
use Martis\Console\RolesScaffoldCommand;
use Martis\Console\SsoMakeCommand;
use Martis\Console\ThemeMakeCommand;
use Martis\Console\ToolMakeCommand;
use Martis\Support\ThemeFiles;
use Symfony\Component\Console\Input\ArrayInput;
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
    $input = new ArrayInput($arguments, $command->getDefinition());
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

it('fails on an existing theme without asking when there is no TTY, and asks on one', function (bool $tty, array $asked) {
    $command = onTerminal(new class extends ThemeMakeCommand
    {
        use FakeTerminal;
    }, $tty, ['name' => 'asked-theme']);

    withExistingFile(ThemeFiles::sourcePath('asked-theme'), function () use ($command, $asked) {
        expect($command->handle())->toBe(Command::FAILURE)
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

it('leaves no command in src/Console asking on isInteractive() alone', function () {
    $offenders = [];
    foreach (glob(dirname(__DIR__, 2).'/src/Console/*.php') ?: [] as $file) {
        if (str_contains((string) file_get_contents($file), 'isInteractive()')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});
