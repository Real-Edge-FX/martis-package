<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:partition')]
class PartitionMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:partition {name : The partition metric class name (e.g. UsersByRole)}';

    protected $description = 'Create a new Martis partition metric class';

    protected $type = 'Martis partition metric';

    protected function getStub(): string
    {
        return StubResolver::path('metric.partition.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
