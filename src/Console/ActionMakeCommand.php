<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:action')]
class ActionMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:action {name : The action class name (e.g. SendEmail)} {--destructive : Create a destructive action}';

    protected $description = 'Create a new Martis action class';

    protected $type = 'Martis action';

    /** Get the stub file for the generator. */
    protected function getStub(): string
    {
        if ($this->option('destructive')) {
            return __DIR__.'/../../../stubs/action.destructive.stub';
        }

        return __DIR__.'/../../../stubs/action.stub';
    }

    /** Get the default namespace for the class. */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Actions';
    }
}
