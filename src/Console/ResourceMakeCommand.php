<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:resource')]
class ResourceMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:resource {name : The resource class name (e.g. Post)}';

    protected $description = 'Create a new Martis resource class';

    protected $type = 'Martis resource';

    /** Get the stub file for the generator. */
    protected function getStub(): string
    {
        return StubResolver::path('resource.stub');
    }

    /** Get the default namespace for the class. */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Resources';
    }

    /**
     * Qualify the given name — appends "Resource" suffix if absent.
     */
    protected function qualifyClass($name): string
    {
        $name = ltrim($name, '\\/');

        if (! Str::endsWith($name, 'Resource')) {
            $name .= 'Resource';
        }

        return parent::qualifyClass($name);
    }

    /**
     * Derive the model name from the resource name.
     * "PostResource" → "Post"
     */
    protected function getModelName(): string
    {
        /** @var string $arg */
        $arg = $this->argument('name');
        $base = class_basename($arg);

        return Str::studly(Str::before($base, 'Resource') ?: $base);
    }

    /** Build the class with the given name. */
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $model = $this->getModelName();

        return str_replace(
            ['{{ model }}', '{{model}}'],
            $model,
            $stub
        );
    }
}
