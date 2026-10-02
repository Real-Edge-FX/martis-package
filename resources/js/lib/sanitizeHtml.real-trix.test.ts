import { afterEach, describe, expect, it, vi } from 'vitest'
import { sanitizeRichText } from './sanitizeHtml'

/*
 * The class allow-list of the `richText` profile is checked against what the
 * real Trix editor serialises, not markup typed by hand: an allow-list
 * narrower than Trix's own output would strip the attachment classes the
 * display's click handler and the gallery layout read.
 */

vi.hoisted(() => {
  delete (window as unknown as { ElementInternals?: unknown }).ElementInternals
})

import 'trix'

type TrixEditorElement = HTMLElement & {
  editor: { insertAttachment(attachment: unknown): void; insertAttachments(attachments: unknown[]): void }
  value: string
}

const Trix = (window as unknown as { Trix: { Attachment: new (attributes: Record<string, unknown>) => unknown } }).Trix

async function serialise(attachments: Array<Record<string, unknown>>): Promise<string> {
  document.body.innerHTML = '<input type="hidden" id="trix-in"><trix-editor input="trix-in"></trix-editor>'
  const editor = document.querySelector('trix-editor') as TrixEditorElement
  await vi.waitFor(() => expect(editor.editor).toBeTruthy())
  editor.editor.insertAttachments(attachments.map((attributes) => new Trix.Attachment(attributes)))

  return (document.getElementById('trix-in') as HTMLInputElement).value || editor.value
}

describe('richText profile against the real Trix serialisation', () => {
  afterEach(async () => {
    document.body.innerHTML = ''
    // Let Trix's mutation observer drain before the environment goes away.
    await new Promise((resolve) => setTimeout(resolve, 20))
  })

  it('keeps the classes Trix writes for an image gallery and a file, and the attachment JSON', async () => {
    const html = await serialise([
      { contentType: 'image/png', url: 'https://cdn.example.com/a.png', filename: 'a.png', filesize: 1024, width: 120, height: 80 },
      { contentType: 'image/png', url: 'https://cdn.example.com/b.png', filename: 'b.png', filesize: 2048, width: 120, height: 80 },
      { contentType: 'application/pdf', href: 'https://cdn.example.com/r.pdf', filename: 'r.pdf', filesize: 10 },
    ])
    // The fixture is real Trix output: it carries the classes under test.
    for (const cls of ['attachment-gallery attachment-gallery--2', 'attachment attachment--preview attachment--png', 'attachment__caption', 'attachment__name', 'attachment__size', 'attachment attachment--file attachment--pdf']) {
      expect(html).toContain(`class="${cls}"`)
    }

    const clean = sanitizeRichText(html)
    const classesOf = (doc: string): string[] =>
      Array.from(new DOMParser().parseFromString(doc, 'text/html').querySelectorAll('[class]')).map((el) => el.getAttribute('class') as string)

    expect(classesOf(clean)).toEqual(classesOf(html))
    expect(clean).toContain('data-trix-attachment')
  })
})
