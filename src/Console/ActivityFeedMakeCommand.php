<?php

namespace Martis\Console;

use Illuminate\Console\GeneratorCommand;
use Martis\Stubs\StubResolver;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'martis:activity-feed')]
class ActivityFeedMakeCommand extends GeneratorCommand
{
    protected $signature = 'martis:activity-feed {name : The activity feed metric class name (e.g. RecentDeploys)}';

    protected $description = 'Create a new Martis activity feed metric class';

    protected $type = 'Martis activity feed metric';

    /** {@inheritdoc} */
    protected function getStub(): string
    {
        return StubResolver::path('metric.activity-feed.stub');
    }

    /** {@inheritdoc} */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Martis\\Metrics';
    }
}
