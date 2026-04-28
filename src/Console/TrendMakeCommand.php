<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:trend')]
class TrendMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:trend {name : The trend metric class name (e.g. UsersPerDay)}';

    protected $description = 'Create a new Martis trend metric class';

    protected $type = 'Martis trend metric';

    protected function getStub(): string
    {
        return StubResolver::path('metric.trend.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
