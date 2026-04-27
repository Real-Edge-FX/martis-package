import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PlayIcon, PauseIcon, DownloadSimpleIcon, TrashIcon, MusicNoteIcon, UploadSimpleIcon } from '@phosphor-icons/react'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface AudioSchema {
  showWaveform?: boolean
  downloadable?: boolean
  acceptedTypes?: string[]
}

type StoredValue = {
  url?: string | null
  name?: string | null
} | File | null | undefined

interface Resolved {
  url: string
  name: string | null
}

function resolveUrl(value: StoredValue): Resolved | null {
  if (!value) return null
  if (value instanceof File) {
    return { url: URL.createObjectURL(value), name: value.name }
  }
  if (typeof value === 'object' && value.url) {
    return { url: value.url, name: value.name ?? null }
  }
  return null
}

function formatTime(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds < 0) return '0:00'
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}:${s.toString().padStart(2, '0')}`
}

/**
 * Decode the audio once and downsample its peaks into an array the
 * canvas can paint in a single pass. We store the peaks in a ref so
 * re-renders don't redecode.
 */
async function decodePeaks(url: string, bucketCount: number): Promise<Float32Array> {
  const Ctx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!Ctx) return new Float32Array(bucketCount)
  const ctx = new Ctx()
  try {
    const res = await fetch(url, { mode: 'cors' })
    if (!res.ok) return new Float32Array(bucketCount)
    const buf = await res.arrayBuffer()
    const audio = await ctx.decodeAudioData(buf.slice(0))
    const channel = audio.getChannelData(0)
    const step = Math.max(1, Math.floor(channel.length / bucketCount))
    const peaks = new Float32Array(bucketCount)
    for (let i = 0; i < bucketCount; i++) {
      let max = 0
      const start = i * step
      const end = Math.min(channel.length, start + step)
      for (let j = start; j < end; j++) {
        const v = Math.abs(channel[j])
        if (v > max) max = v
      }
      peaks[i] = max
    }
    return peaks
  } catch {
    return new Float32Array(bucketCount)
  } finally {
    void ctx.close?.()
  }
}

/**
 * Canvas waveform — paints bars for the decoded peaks. The `progress`
 * prop (0..1) drives the two-tone fill so bars before the playhead use
 * the accent colour and bars after fall back to the muted surface tint.
 */
function Waveform({ url, accent, progress }: { url: string; accent: string; progress: number }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const [ready, setReady] = useState(false)
  const peaksRef = useRef<Float32Array | null>(null)

  useEffect(() => {
    let cancelled = false
    const bucketCount = 80
    void (async () => {
      const peaks = await decodePeaks(url, bucketCount)
      if (cancelled) return
      peaksRef.current = peaks
      setReady(true)
    })()
    return () => {
      cancelled = true
    }
  }, [url])

  useEffect(() => {
    if (!ready || !canvasRef.current || !peaksRef.current) return
    const canvas = canvasRef.current
    const peaks = peaksRef.current
    const dpr = window.devicePixelRatio || 1
    const w = canvas.clientWidth * dpr
    const h = canvas.clientHeight * dpr
    canvas.width = w
    canvas.height = h
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.clearRect(0, 0, w, h)
    const barWidth = w / peaks.length
    const center = h / 2
    const playheadX = progress * w
    for (let i = 0; i < peaks.length; i++) {
      const peak = peaks[i]
      const barHeight = Math.max(2, peak * h * 0.9)
      const x = i * barWidth + 1 * dpr
      const y = center - barHeight / 2
      const width = Math.max(1, barWidth - 2 * dpr)
      ctx.fillStyle = x < playheadX ? accent : 'rgba(128, 128, 128, 0.35)'
      ctx.fillRect(x, y, width, barHeight)
    }
  }, [ready, accent, progress])

  return <canvas ref={canvasRef} className="martis-audio-waveform" aria-hidden="true" />
}

/**
 * Custom inline audio player: play/pause button, waveform (optional) or
 * progress bar, current/total timestamps, and a download affordance.
 * Replaces the native `<audio controls>` element so the look stays
 * on-brand across light and dark themes.
 */
function AudioPlayer({
  resolved,
  showWaveform,
  downloadable,
}: {
  resolved: Resolved
  showWaveform: boolean
  downloadable: boolean
}) {
  const { t } = useTranslation('messages')
  const audioRef = useRef<HTMLAudioElement | null>(null)
  const [playing, setPlaying] = useState(false)
  const [currentTime, setCurrentTime] = useState(0)
  const [duration, setDuration] = useState(0)

  useEffect(() => {
    const el = audioRef.current
    if (!el) return

    const onTime = () => setCurrentTime(el.currentTime)
    const onMeta = () => setDuration(el.duration)
    const onEnd = () => setPlaying(false)

    el.addEventListener('timeupdate', onTime)
    el.addEventListener('loadedmetadata', onMeta)
    el.addEventListener('ended', onEnd)
    return () => {
      el.removeEventListener('timeupdate', onTime)
      el.removeEventListener('loadedmetadata', onMeta)
      el.removeEventListener('ended', onEnd)
    }
  }, [resolved.url])

  const togglePlay = useCallback(() => {
    const el = audioRef.current
    if (!el) return
    if (el.paused) {
      void el.play()
      setPlaying(true)
    } else {
      el.pause()
      setPlaying(false)
    }
  }, [])

  const progress = duration > 0 ? currentTime / duration : 0

  const handleScrub = useCallback((e: React.MouseEvent<HTMLDivElement>) => {
    const el = audioRef.current
    if (!el || duration <= 0) return
    const rect = e.currentTarget.getBoundingClientRect()
    const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
    el.currentTime = ratio * duration
  }, [duration])

  return (
    // The whole player is `data-row-action="suppress"` so any click
    // inside it does NOT bubble to the DataTable's row-click handler.
    // Without it, hitting the play / pause / scrub region in an index
    // table cell would navigate to the row's detail page instead of
    // controlling playback. The marker is read by `Table.tsx` and is
    // additive — other interactive cell content can opt in the same
    // way (e.g. inline file downloads, popovers).
    <div
      className="martis-audio-player"
      data-row-action="suppress"
      onClick={(e) => e.stopPropagation()}
    >
      <button
        type="button"
        className="martis-audio-play-btn"
        onClick={togglePlay}
        aria-label={playing ? t('audio_pause') : t('audio_play')}
      >
        {playing ? <PauseIcon size={14} weight="fill" /> : <PlayIcon size={14} weight="fill" />}
      </button>

      <div className="martis-audio-track">
        {showWaveform ? (
          <div className="martis-audio-track-waveform" onClick={handleScrub} role="slider" aria-valuemin={0} aria-valuemax={duration} aria-valuenow={currentTime}>
            <Waveform url={resolved.url} accent="var(--martis-accent)" progress={progress} />
          </div>
        ) : (
          <div
            className="martis-audio-track-bar"
            onClick={handleScrub}
            role="slider"
            aria-valuemin={0}
            aria-valuemax={duration}
            aria-valuenow={currentTime}
          >
            <div className="martis-audio-track-fill" style={{ width: `${progress * 100}%` }} />
          </div>
        )}
        <div className="martis-audio-time">
          <span>{formatTime(currentTime)}</span>
          <span>{formatTime(duration)}</span>
        </div>
      </div>

      {downloadable && (
        <a
          href={resolved.url}
          download={resolved.name ?? undefined}
          className="martis-audio-download"
          aria-label={t('audio_download')}
          data-pr-tooltip={t('audio_download')}
          data-pr-position="top"
        >
          <DownloadSimpleIcon size={14} />
        </a>
      )}

      <audio ref={audioRef} src={resolved.url} preload="metadata" />
    </div>
  )
}

export function AudioFieldDisplay({ field, value }: FieldDisplayProps) {
  const schema = field as unknown as AudioSchema
  const resolved = resolveUrl(value as StoredValue)
  if (!resolved) {
    return <span className="martis-text-muted">—</span>
  }

  return (
    <AudioPlayer
      resolved={resolved}
      showWaveform={schema.showWaveform !== false}
      downloadable={schema.downloadable !== false}
    />
  )
}

export function AudioFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const schema = field as unknown as AudioSchema
  const accepted = useMemo(() => {
    return (schema.acceptedTypes ?? ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'])
      .map((ext) => `.${ext}`)
      .join(',')
  }, [schema.acceptedTypes])
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [dragOver, setDragOver] = useState(false)
  const resolved = resolveUrl(value as StoredValue)

  const handleFile = useCallback((file: File | null | undefined) => {
    if (file) onChange(file)
  }, [onChange])

  const handleDrop = useCallback((e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault()
    setDragOver(false)
    handleFile(e.dataTransfer.files?.[0])
  }, [handleFile])

  const wrapClass = [
    'martis-audio-input',
    error ? 'has-error' : '',
    dragOver ? 'is-drag-over' : '',
    resolved ? 'has-file' : 'is-empty',
  ].filter(Boolean).join(' ')

  return (
    <div
      className={wrapClass}
      onDragOver={(e) => {
        e.preventDefault()
        if (!field.readonly) setDragOver(true)
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(e) => {
        if (field.readonly) return
        handleDrop(e)
      }}
    >
      {resolved ? (
        <>
          <AudioPlayer
            resolved={resolved}
            showWaveform={schema.showWaveform !== false}
            downloadable={schema.downloadable !== false}
          />
          {!field.readonly && (
            <div className="martis-audio-input-controls">
              <button type="button" className="martis-btn-secondary" onClick={() => inputRef.current?.click()}>
                <UploadSimpleIcon size={13} weight="bold" />
                {t('audio_replace')}
              </button>
              <button type="button" className="martis-btn-ghost martis-audio-remove" onClick={() => onChange(null)}>
                <TrashIcon size={13} weight="bold" />
                {t('audio_remove')}
              </button>
            </div>
          )}
        </>
      ) : (
        <button
          type="button"
          className="martis-audio-dropzone"
          onClick={() => !field.readonly && inputRef.current?.click()}
          disabled={field.readonly}
        >
          <span className="martis-audio-dropzone-icon" aria-hidden="true">
            <MusicNoteIcon size={22} weight="duotone" />
          </span>
          <span className="martis-audio-dropzone-title">{t('audio_empty_title')}</span>
          <span className="martis-audio-dropzone-hint">{t('audio_empty_hint')}</span>
          <span className="martis-audio-dropzone-cta">
            <UploadSimpleIcon size={13} weight="bold" />
            {t('audio_browse')}
          </span>
        </button>
      )}

      <input
        ref={inputRef}
        type="file"
        accept={accepted}
        style={{ display: 'none' }}
        onChange={(e) => handleFile(e.target.files?.[0])}
      />
      {error && <p className="martis-field-error">{error}</p>}
    </div>
  )
}
