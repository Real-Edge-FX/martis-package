import { useState } from "react"
import type { FieldDisplayProps, FieldInputProps } from "./types"

interface GravatarExt {
  shape?: "rounded" | "squared"
  avatarSize?: number
  sourceType?: "email" | "url"
}

function getExt(field: Record<string, unknown>): GravatarExt {
  return field as unknown as GravatarExt
}

function GravatarSilhouette({ size, borderRadius }: { size: number; borderRadius: string }) {
  return (
    <span
      className={`inline-flex items-center justify-center bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 ${borderRadius}`}
      style={{ width: size, height: size, flexShrink: 0 }}
      aria-label="No avatar"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="currentColor"
        style={{ width: size * 0.6, height: size * 0.6 }}
      >
        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" />
      </svg>
    </span>
  )
}

function GravatarImage({ url, ext }: { url: string; ext: GravatarExt }) {
  const size = ext.avatarSize ?? 40
  const shape = ext.shape ?? "rounded"
  const borderRadius = shape === "rounded" ? "rounded-full" : "rounded"
  const [broken, setBroken] = useState(false)

  if (broken) {
    return <GravatarSilhouette size={size} borderRadius={borderRadius} />
  }

  return (
    <img
      src={url}
      alt="Avatar"
      width={size}
      height={size}
      className={`inline-block object-cover ${borderRadius}`}
      loading="lazy"
      onError={() => setBroken(true)}
    />
  )
}

export function GravatarFieldDisplay({ field, value }: FieldDisplayProps) {
  const ext = getExt(field as unknown as Record<string, unknown>)
  const size = ext.avatarSize ?? 40
  const shape = ext.shape ?? "rounded"
  const borderRadius = shape === "rounded" ? "rounded-full" : "rounded"

  if (value === null || value === undefined || value === "") {
    return <GravatarSilhouette size={size} borderRadius={borderRadius} />
  }

  const url = String(value)

  return <GravatarImage url={url} ext={ext} />
}

export function GravatarFieldInput({ field, value }: FieldInputProps) {
  const ext = getExt(field as unknown as Record<string, unknown>)
  const size = ext.avatarSize ?? 40
  const shape = ext.shape ?? "rounded"
  const borderRadius = shape === "rounded" ? "rounded-full" : "rounded"

  if (value === null || value === undefined || value === "") {
    return (
      <div className="flex items-center py-2">
        <GravatarSilhouette size={size} borderRadius={borderRadius} />
        <span className="ml-3 text-sm martis-text-muted">No avatar set</span>
      </div>
    )
  }

  const url = String(value)

  return (
    <div className="flex items-center py-2">
      <GravatarImage url={url} ext={ext} />
      <span className="ml-3 text-sm martis-text-muted truncate max-w-xs">
        {url}
      </span>
    </div>
  )
}
