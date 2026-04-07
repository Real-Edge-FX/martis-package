<?php

namespace Martis\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Generate a Martis resource policy class.
 *
 * Creates a policy stub in the configured namespace (default: App\Martis\Policies)
 * with all resource, action, and relationship ability methods pre-defined.
 *
 * Nova v5 parity: equivalent to `php artisan nova:policy`.
 */
class PolicyMakeCommand extends Command
{
    protected $signature = 'martis:make-policy
        {name : The name of the policy class (e.g. UserPolicy)}
        {--model= : The Eloquent model class (short name or FQCN)}
        {--resource= : The Martis resource class (short name)}';

    protected $description = 'Create a new Martis resource policy class';

    public function handle(Filesystem $files): int
    {
        $nameArg = $this->argument('name');
        assert(is_string($nameArg));
        $name = Str::studly($nameArg);

        // Ensure it ends with Policy
        if (! str_ends_with($name, 'Policy')) {
            $name .= 'Policy';
        }

        $namespace = (string) config('martis.policy_namespace', 'App\\Martis\\Policies');
        $directory = base_path(str_replace('\\', '/', $namespace));
        $path = $directory.'/'.$name.'.php';

        if ($files->exists($path)) {
            $this->components->error("Policy [{$name}] already exists.");

            return self::FAILURE;
        }

        // Determine model name for type hints
        $modelOption = $this->option('model');
        $modelName = 'Model';
        $modelImport = 'Illuminate\\Database\\Eloquent\\Model';

        if (is_string($modelOption) && $modelOption !== '') {
            if (str_contains($modelOption, '\\')) {
                $modelImport = $modelOption;
                $modelName = class_basename($modelOption);
            } else {
                $modelName = Str::studly($modelOption);
                $modelImport = 'App\\Models\\'.$modelName;
            }
        }

        $stub = $files->get(__DIR__.'/../../stubs/policy.stub');

        $stub = str_replace([
            '{{ namespace }}',
            '{{ class }}',
            '{{ modelImport }}',
            '{{ model }}',
        ], [
            $namespace,
            $name,
            $modelImport,
            $modelName,
        ], $stub);

        $files->ensureDirectoryExists($directory);
        $files->put($path, $stub);

        $this->components->info("Policy [{$namespace}\\{$name}] created successfully.");

        return self::SUCCESS;
    }
}
