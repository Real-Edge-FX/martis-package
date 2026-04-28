<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:endpoint-table')]
class EndpointTableMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:endpoint-table {name : The endpoint table metric class name (e.g. TopEndpoints)}';

    protected $description = 'Create a new Martis endpoint table metric class';

    protected $type = 'Martis endpoint table metric';

    /** {@inheritdoc} */
    protected function getStub(): string
    {
        return StubResolver::path('metric.endpoint-table.stub');
    }

    /** {@inheritdoc} */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
