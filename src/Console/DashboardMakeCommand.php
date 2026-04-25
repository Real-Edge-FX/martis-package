<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:dashboard')]
class DashboardMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:dashboard {name : The dashboard class name (e.g. SalesDashboard)}';

    protected $description = 'Create a new Martis dashboard class';

    protected $type = 'Martis dashboard';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/dashboard.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Dashboards';
    }
}
