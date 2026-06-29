<?php

declare(strict_types=1);

use Martis\Support\EnvFilePatcher;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/martis-envpatcher-'.uniqid();
    mkdir($this->base, 0755, true);
});

afterEach(function () {
    if (isset($this->base) && is_dir($this->base)) {
        // scandir (not glob) so dotfiles like `.env` are removed too —
        // glob('*') skips them and rmdir would then fail "not empty".
        foreach (scandir($this->base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($this->base.'/'.$entry);
        }
        rmdir($this->base);
    }
});

it('sets a key with a comment in an env file', function () {
    file_put_contents($this->base.'/.env', "APP_KEY=base64:abc\n");
    $patcher = new EnvFilePatcher($this->base);

    $result = $patcher->set('.env', 'MARTIS_MCP_ENABLED', 'true', 'Set to false to disable');

    expect($result)->toBeTrue();
    $contents = (string) file_get_contents($this->base.'/.env');
    expect($contents)
        ->toContain('# Set to false to disable')
        ->toContain('MARTIS_MCP_ENABLED=true');
});

it('returns false when the key already exists', function () {
    file_put_contents($this->base.'/.env', "MARTIS_MCP_ENABLED=false\n");
    $patcher = new EnvFilePatcher($this->base);

    $result = $patcher->set('.env', 'MARTIS_MCP_ENABLED', 'true', 'comment');

    expect($result)->toBeFalse();
    expect((string) file_get_contents($this->base.'/.env'))->toContain('MARTIS_MCP_ENABLED=false');
});

it('removes a key that was added with a comment, leaving no orphaned blank lines', function () {
    // Simulate the state left by set() — one blank separator line, one
    // comment line, one key line at the end of the file.
    file_put_contents($this->base.'/.env', "APP_KEY=base64:abc\n\n# Set to false to disable\nMARTIS_MCP_ENABLED=true\n");
    $patcher = new EnvFilePatcher($this->base);

    $result = $patcher->remove('.env', 'MARTIS_MCP_ENABLED');

    expect($result)->toBeTrue();
    $contents = (string) file_get_contents($this->base.'/.env');

    // The comment and key are gone.
    expect($contents)
        ->not->toContain('MARTIS_MCP_ENABLED')
        ->not->toContain('Set to false to disable');

    // No trailing blank line should remain after removal.
    expect(rtrim($contents))->toBe('APP_KEY=base64:abc');
});

it('removes a key added without a comment, leaving no orphaned blank lines', function () {
    // set() with $comment = null still writes a blank separator line
    // before the key. remove() must clean that up too.
    file_put_contents($this->base.'/.env', "APP_KEY=base64:abc\n\nMARTIS_MCP_ENABLED=true\n");
    $patcher = new EnvFilePatcher($this->base);

    $result = $patcher->remove('.env', 'MARTIS_MCP_ENABLED');

    expect($result)->toBeTrue();
    $contents = (string) file_get_contents($this->base.'/.env');
    expect($contents)->not->toContain('MARTIS_MCP_ENABLED');
    expect(rtrim($contents))->toBe('APP_KEY=base64:abc');
});

it('does not strip blank lines that existed in the file before the key was added', function () {
    // The user may have intentional blank lines in their .env that
    // group settings. remove() must not consume more than the separator
    // blank line that set() appended.
    file_put_contents($this->base.'/.env', "GROUP_A=1\n\nGROUP_B=2\n\n# Set to false to disable\nMARTIS_MCP_ENABLED=true\n");
    $patcher = new EnvFilePatcher($this->base);
    $patcher->remove('.env', 'MARTIS_MCP_ENABLED');

    $contents = (string) file_get_contents($this->base.'/.env');

    // The blank line that separates GROUP_A from GROUP_B must survive.
    expect($contents)->toContain("GROUP_A=1\n\nGROUP_B=2");
    expect($contents)->not->toContain('MARTIS_MCP_ENABLED');
});
