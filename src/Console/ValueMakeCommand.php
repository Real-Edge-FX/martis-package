<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:value')]
class ValueMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:value {name : The value metric class name (e.g. TotalUsers)}';

    protected $description = 'Create a new Martis value metric class';

    protected $type = 'Martis value metric';

    protected function getStub(): string
    {
        return __DIR__.'/../../../stubs/metric.value.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
