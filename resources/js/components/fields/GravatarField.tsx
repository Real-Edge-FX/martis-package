import type { FieldDisplayProps, FieldInputProps } from "./types"

interface GravatarExt {
  shape?: "rounded" | "squared"
  avatarSize?: number
  sourceType?: "email" | "url"
}

function getExt(field: Record<string, unknown>): GravatarExt {
  return field as unknown as GravatarExt
}

function GravatarImage({ url, ext }: { url: string; ext: GravatarExt }) {
  const size = ext.avatarSize ?? 40
  const shape = ext.shape ?? "rounded"
  const borderRadius = shape === "rounded" ? "rounded-full" : "rounded"

  return (
    <img
      src={url}
      alt="Avatar"
      width={size}
      height={size}
      className={`inline-block object-cover ${borderRadius}`}
      loading="lazy"
    />
  )
}

export function GravatarFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === "") {
    return <span className="text-gray-400 dark:text-gray-500">\u2014</span>
  }

  const ext = getExt(field as unknown as Record<string, unknown>)
  const url = String(value)

  return <GravatarImage url={url} ext={ext} />
}

export function GravatarFieldInput({ field, value }: FieldInputProps) {
  if (value === null || value === undefined || value === "") {
    return <span className="text-gray-400 dark:text-gray-500">\u2014</span>
  }

  const ext = getExt(field as unknown as Record<string, unknown>)
  const url = String(value)

  return (
    <div className="flex items-center py-2">
      <GravatarImage url={url} ext={ext} />
      <span className="ml-3 text-sm martis-text-muted truncate max-w-xs">
        {ext.sourceType === "url" ? url : url}
      </span>
    </div>
  )
}
