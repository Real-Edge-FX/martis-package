<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Schema;
use Martis\Console\SsoMakeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * martis:sso offers the role mapping (one question per Spatie role) only
 * on a terminal, as martis:install asks: through a pipe the questions
 * would read it or wait forever. Both share AsksOnlyOnATerminal.
 */

function ssoCommandWithTty(bool $tty): SsoMakeCommand
{
    $command = new class($tty) extends SsoMakeCommand
    {
        public function __construct(private bool $tty)
        {
            parent::__construct();
        }

        protected function insideUnitTests(): bool
        {
            return false;
        }

        protected function stdinIsTty(): bool
        {
            return $this->tty;
        }
    };
    $command->setLaravel(app());
    $input = new ArrayInput(['provider' => 'azure'], $command->getDefinition());
    $input->setInteractive(true);
    (fn () => $this->input = $input)->call($command);
    (fn () => $this->output = new OutputStyle($input, new BufferedOutput))->call($command);

    return $command;
}

beforeEach(function () {
    config(['martis.auth.sso.providers.azure' => ['role_strategy' => 'column']]);
    Schema::dropIfExists('roles');
    Schema::create('roles', function ($table) {
        $table->id();
        $table->string('name');
    });
});

afterEach(function () {
    Schema::dropIfExists('roles');
});

it('does not offer the role mapping without a TTY', function () {
    expect((fn () => $this->shouldOfferRoleMapping('azure'))->call(ssoCommandWithTty(false)))->toBeFalse();
});

it('offers the role mapping on a TTY (control)', function () {
    expect((fn () => $this->shouldOfferRoleMapping('azure'))->call(ssoCommandWithTty(true)))->toBeTrue();
});
