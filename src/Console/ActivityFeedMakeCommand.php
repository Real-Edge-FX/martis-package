<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:activity-feed')]
class ActivityFeedMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:activity-feed {name : The activity feed metric class name (e.g. RecentDeploys)}';

    protected $description = 'Create a new Martis activity feed metric class';

    protected $type = 'Martis activity feed metric';

    /** {@inheritDoc} */
    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/metric.activity-feed.stub';
    }

    /** {@inheritDoc} */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
