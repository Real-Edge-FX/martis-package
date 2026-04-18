<?php

namespace Martis\Fields;

/**
 * Audio — file upload specialised for audio clips.
 *
 * Laravel Nova v5 parity: Audio field.
 * Reference: https://nova.laravel.com/docs/v5/resources/fields#audio-field
 *
 * Extends {@see File} so every upload helper (`disk`, `storagePath`,
 * `maxSize`, `preserveOriginalName`, `sanitizeFileName`, …) carries
 * over. Frontend renders an inline `<audio>` player plus a waveform
 * preview drawn on a `<canvas>` — no server-side rendering required.
 *
 * ⭐ Martis differentials:
 *  - **Waveform preview** — the frontend samples the decoded audio
 *    once on load and paints the peaks into a canvas. Zero server
 *    processing, zero external dependencies, works from any storage
 *    backend that serves the URL with CORS.
 *  - **Native Martis player chrome** — uses the package's Tooltip and
 *    icon system so the player matches drawers, modals and the rest
 *    of the UI by default.
 *  - `downloadable(bool)` — toggle the download button.
 */
class Audio extends File
{
    /** @var list<string> Accepted audio extensions (no video / exe). */
    protected array $acceptedTypes = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];

    protected bool $showWaveform = true;

    protected bool $downloadable = true;

    public function type(): string
    {
        return 'audio';
    }

    /** ⭐ Toggle the canvas waveform. Defaults to on. */
    public function waveform(bool $enabled = true): static
    {
        $this->showWaveform = $enabled;

        return $this;
    }

    /** Toggle the download button on the player. */
    public function downloadable(bool $enabled = true): static
    {
        $this->downloadable = $enabled;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        return array_merge(parent::extraAttributes(), [
            'showWaveform' => $this->showWaveform,
            'downloadable' => $this->downloadable,
        ]);
    }
}
