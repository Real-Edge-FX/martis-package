<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:filter')]
class FilterMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:filter {name : The filter class name (e.g. StatusFilter)} {--boolean : Create a boolean filter} {--date : Create a date filter}';

    protected $description = 'Create a new Martis filter class';

    protected $type = 'Martis filter';

    /** Get the stub file for the generator. */
    protected function getStub(): string
    {
        if ($this->option('boolean')) {
            return StubResolver::path('filter.boolean.stub');
        }

        if ($this->option('date')) {
            return StubResolver::path('filter.date.stub');
        }

        return StubResolver::path('filter.select.stub');
    }

    /** Get the default namespace for the class. */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Filters';
    }
}
