<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:progress')]
class ProgressMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:progress {name : The progress metric class name (e.g. MonthlyGoal)}';

    protected $description = 'Create a new Martis progress metric class';

    protected $type = 'Martis progress metric';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/metric.progress.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
