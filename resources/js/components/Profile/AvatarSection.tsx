import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from 'primereact/button'
import { Camera, Trash } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_SIZE_MB = 5

interface AvatarSectionProps {
  avatarUrl: string | null
  name: string
  onUpdate: (url: string | null) => void
}

export function AvatarSection({ avatarUrl, name, onUpdate }: AvatarSectionProps) {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [pendingFile, setPendingFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)
  const [removing, setRemoving] = useState(false)

  const initials = (name || '?')
    .split(' ')
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return

    if (!ALLOWED_TYPES.includes(file.type)) {
      addToast('error', t('avatar_invalid_type'))
      return
    }
    if (file.size > MAX_SIZE_MB * 1024 * 1024) {
      addToast('error', t('avatar_too_large', { size: String(MAX_SIZE_MB) }))
      return
    }

    setPendingFile(file)
    const reader = new FileReader()
    reader.onload = (ev) => setPreview(ev.target?.result as string)
    reader.readAsDataURL(file)
  }

  async function handleUpload() {
    if (!pendingFile) return

    setUploading(true)
    try {
      const res = await api.upload<{ url: string }>('POST', '/api/profile/avatar', { avatar: pendingFile })
      onUpdate(res.url)
      setPreview(null)
      setPendingFile(null)
      addToast('success', t('avatar_uploaded'))
    } catch (err) {
      if (err instanceof ApiError) {
        addToast('error', err.message || t('error'))
      } else {
        addToast('error', t('error'))
      }
    } finally {
      setUploading(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
  }

  async function handleRemove() {
    setRemoving(true)
    try {
      await api.delete('/api/profile/avatar')
      onUpdate(null)
      setPreview(null)
      setPendingFile(null)
      addToast('success', t('avatar_removed'))
    } catch {
      addToast('error', t('error'))
    } finally {
      setRemoving(false)
    }
  }

  const displaySrc = preview ?? avatarUrl

  return (
    <section
      className="rounded-xl p-6 border martis-border martis-card-bg"
      aria-labelledby="avatar-section-title"
    >
      <h2 id="avatar-section-title" className="text-lg font-semibold martis-text mb-4">
        {t('avatar')}
      </h2>

      <div className="flex items-center gap-6">
        <div className="relative flex-shrink-0">
          {displaySrc ? (
            <img
              src={displaySrc}
              alt={name}
              className="h-20 w-20 rounded-full object-cover border-2 martis-border"
            />
          ) : (
            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-indigo-600 text-white text-2xl font-bold border-2 martis-border">
              {initials}
            </div>
          )}
        </div>

        <div className="flex flex-col gap-3">
          {preview ? (
            <div className="flex gap-2">
              <Button
                type="button"
                label={uploading ? t('avatar_uploading') : t('avatar_upload')}
                icon={<Camera size={16} />}
                loading={uploading}
                onClick={() => void handleUpload()}
                raised
                size="small"
              />
              <Button
                type="button"
                label={t('avatar_change')}
                outlined
                size="small"
                onClick={() => {
                  setPreview(null)
                  setPendingFile(null)
                  if (fileInputRef.current) fileInputRef.current.value = ''
                }}
              />
            </div>
          ) : (
            <Button
              type="button"
              label={avatarUrl ? t('avatar_change') : t('avatar_upload')}
              icon={<Camera size={16} />}
              onClick={() => fileInputRef.current?.click()}
              raised
              size="small"
            />
          )}

          {avatarUrl && !preview && (
            <Button
              type="button"
              label={removing ? t('avatar_uploading') : t('avatar_remove')}
              icon={<Trash size={16} />}
              severity="danger"
              outlined
              size="small"
              loading={removing}
              onClick={() => void handleRemove()}
            />
          )}

          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            aria-label={t('avatar_upload')}
            onChange={handleFileChange}
          />
        </div>
      </div>
    </section>
  )
}
