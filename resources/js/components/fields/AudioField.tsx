import { useEffect, useRef, useState } from 'react'
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

function resolveUrl(value: StoredValue): { url: string; name: string | null } | null {
  if (!value) return null
  if (value instanceof File) {
    return { url: URL.createObjectURL(value), name: value.name }
  }
  if (typeof value === 'object' && value.url) {
    return { url: value.url, name: value.name ?? null }
  }
  return null
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

function Waveform({ url, accent }: { url: string; accent: string }) {
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
    ctx.fillStyle = accent
    for (let i = 0; i < peaks.length; i++) {
      const peak = peaks[i]
      const barHeight = Math.max(2, peak * h * 0.9)
      ctx.fillRect(i * barWidth + 1 * dpr, center - barHeight / 2, Math.max(1, barWidth - 2 * dpr), barHeight)
    }
  }, [ready, accent])

  return <canvas ref={canvasRef} className="martis-audio-waveform" aria-hidden="true" />
}

export function AudioFieldDisplay({ field, value }: FieldDisplayProps) {
  const schema = field as unknown as AudioSchema
  const resolved = resolveUrl(value as StoredValue)
  if (!resolved) {
    return <span className="martis-text-muted">—</span>
  }

  return (
    <div className="martis-audio">
      {schema.showWaveform !== false && <Waveform url={resolved.url} accent="var(--martis-accent)" />}
      <audio
        className="martis-audio-player"
        src={resolved.url}
        controls
        controlsList={schema.downloadable === false ? 'nodownload' : undefined}
        preload="metadata"
      />
    </div>
  )
}

export function AudioFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const schema = field as unknown as AudioSchema
  const accepted = (schema.acceptedTypes ?? ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'])
    .map((ext) => `.${ext}`)
    .join(',')
  const inputRef = useRef<HTMLInputElement | null>(null)
  const resolved = resolveUrl(value as StoredValue)

  return (
    <div className={`martis-audio-input${error ? ' has-error' : ''}`}>
      {resolved ? (
        <AudioFieldDisplay field={field} value={value} />
      ) : (
        <p className="martis-text-muted martis-audio-empty">Sem áudio anexado</p>
      )}
      <div className="martis-audio-input-controls">
        <button type="button" className="martis-btn-secondary" onClick={() => inputRef.current?.click()}>
          {resolved ? 'Substituir áudio' : 'Carregar áudio'}
        </button>
        {resolved && (
          <button type="button" className="martis-btn-secondary" onClick={() => onChange(null)}>
            Remover
          </button>
        )}
      </div>
      <input
        ref={inputRef}
        type="file"
        accept={accepted}
        style={{ display: 'none' }}
        onChange={(e) => onChange(e.target.files?.[0] ?? null)}
      />
      {error && <p className="martis-field-error">{error}</p>}
    </div>
  )
}
