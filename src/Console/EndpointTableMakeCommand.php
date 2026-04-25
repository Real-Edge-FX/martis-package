<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:endpoint-table')]
class EndpointTableMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:endpoint-table {name : The endpoint table metric class name (e.g. TopEndpoints)}';

    protected $description = 'Create a new Martis endpoint table metric class';

    protected $type = 'Martis endpoint table metric';

    /** {@inheritDoc} */
    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/metric.endpoint-table.stub';
    }

    /** {@inheritDoc} */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
