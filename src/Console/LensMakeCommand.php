<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:lens')]
class LensMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:lens {name : The lens class name (e.g. MostValuableClients)}';

    protected $description = 'Create a new Martis lens class';

    protected $type = 'Martis lens';

    /** Get the stub file for the generator. */
    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/lens.stub';
    }

    /** Get the default namespace for the class. */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Lenses';
    }
}
