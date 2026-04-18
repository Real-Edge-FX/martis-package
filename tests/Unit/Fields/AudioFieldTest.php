<?php

use Martis\Fields\Audio;

it('Audio::make creates an audio field', function () {
    $field = Audio::make('intro');

    expect($field->type())->toBe('audio');
});

it('Audio default accepted types cover common formats', function () {
    $schema = Audio::make('intro')->toArray();

    // Audio extends File — File emits acceptedTypes in its extras.
    expect($schema['acceptedTypes'])->toContain('mp3')
        ->and($schema['acceptedTypes'])->toContain('wav')
        ->and($schema['acceptedTypes'])->toContain('ogg');
});

it('Audio showWaveform defaults to true and is emitted in the schema', function () {
    $schema = Audio::make('intro')->toArray();

    expect($schema['showWaveform'])->toBeTrue();
});

it('Audio waveform(false) toggles the canvas off', function () {
    $schema = Audio::make('intro')->waveform(false)->toArray();

    expect($schema['showWaveform'])->toBeFalse();
});

it('Audio downloadable defaults to true and is emitted', function () {
    $schema = Audio::make('intro')->toArray();

    expect($schema['downloadable'])->toBeTrue();
});

it('Audio downloadable(false) disables the download button', function () {
    $schema = Audio::make('intro')->downloadable(false)->toArray();

    expect($schema['downloadable'])->toBeFalse();
});
