<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('keeps the AGENTS.md stub aligned with the package surface', function () {
    $stub = file_get_contents(__DIR__.'/../../stubs/agents/AGENTS.md.stub');
    expect($stub)->toBeString()->not->toBeEmpty();

    $errors = [];

    // Artisan signatures that the stub claims exist.
    preg_match_all('/`(martis:[a-z][a-z0-9:_-]*)`/i', (string) $stub, $matches);
    $known = array_keys(Artisan::all());
    foreach (array_unique($matches[1] ?? []) as $command) {
        $base = explode(' ', (string) $command)[0];
        if (! in_array($base, $known, true)) {
            $errors[] = "Stub mentions unknown artisan command: {$base}";
        }
    }

    // Class refs from the Martis namespace mentioned in prose.
    preg_match_all('/`(Martis\\\\[A-Z][A-Za-z0-9_\\\\]*)`/', (string) $stub, $classMatches);
    foreach (array_unique($classMatches[1] ?? []) as $class) {
        $fqcn = str_replace('\\\\', '\\', (string) $class);
        if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn)) {
            $errors[] = "Stub mentions unknown class/trait/interface: {$fqcn}";
        }
    }

    // MARTIS_* env vars must be referenced somewhere in the package
    // (config/martis.php, source files, or the install command).
    preg_match_all('/`(MARTIS_[A-Z0-9_]+)`/', (string) $stub, $envMatches);
    $needles = array_unique($envMatches[1] ?? []);
    if ($needles !== []) {
        $haystack = '';
        foreach (['config/martis.php', 'src'] as $relative) {
            $absolute = __DIR__.'/../../'.$relative;
            if (is_file($absolute)) {
                $haystack .= file_get_contents($absolute);
            } elseif (is_dir($absolute)) {
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute)) as $file) {
                    if ($file->isFile() && in_array($file->getExtension(), ['php', 'stub'], true)) {
                        $haystack .= file_get_contents($file->getPathname());
                    }
                }
            }
        }
        foreach ($needles as $env) {
            if (! str_contains($haystack, $env)) {
                $errors[] = "Stub mentions env var {$env} but it is not referenced in config/ or src/.";
            }
        }
    }

    // MCP tool names listed in the conditional section.
    foreach (['martis_doc_list', 'martis_doc_read', 'martis_doc_search'] as $tool) {
        if (! str_contains((string) $stub, $tool)) {
            continue;
        }
        $toolsClass = file_get_contents(__DIR__.'/../../src/Mcp/Tools.php');
        $expectedMethod = match ($tool) {
            'martis_doc_list' => 'listDocs',
            'martis_doc_read' => 'readDoc',
            'martis_doc_search' => 'searchDocs',
        };
        if (! str_contains((string) $toolsClass, "function {$expectedMethod}(")) {
            $errors[] = "Stub mentions MCP tool {$tool} but Tools::{$expectedMethod}() is missing.";
        }
    }

    // MCP section markers must come in matching pairs.
    $opens = substr_count((string) $stub, '{{MCP_SECTION}}');
    $closes = substr_count((string) $stub, '{{/MCP_SECTION}}');
    if ($opens !== $closes) {
        $errors[] = "MCP section markers unbalanced (open: {$opens}, close: {$closes}).";
    }

    expect($errors)->toBe([], implode("\n", $errors));
});
