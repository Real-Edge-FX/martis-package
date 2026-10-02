import createDOMPurify, { type Config, type DOMPurify, type ElementHook, type UponSanitizeAttributeHook } from 'dompurify'
import { isSafeNavigationUrl } from './safeUrl'

/**
 * The one place the SPA sanitises HTML before it reaches `innerHTML`
 * (`dangerouslySetInnerHTML`). Every sink goes through a profile of this
 * module, so the rules live in one file instead of one `DOMPurify.sanitize()`
 * call per component, and a sink cannot be added without picking one.
 *
 * Profiles, by who writes the HTML:
 *
 *   - `richText`: a Trix document. Written by panel users and stored as raw
 *     HTML, so it is untrusted. Keeps the figure / figcaption of an
 *     attachment and the three `data-trix-*` attributes the display reads,
 *     and drops an attachment whose JSON names a URL a person must not be
 *     sent to.
 *   - `markdown`: the output of `marked` for a Markdown field. Untrusted, as
 *     `marked` passes raw HTML through verbatim.
 *   - `markup`: a short inline fragment the application developer wrote (the
 *     `help()` text of a field, the body of an HTML tooltip, the message of a
 *     gate modal): links (a `target` is kept, with `rel="noopener noreferrer"`
 *     forced), bold, code. Trusted, sanitised as a second barrier for an
 *     application that interpolates user data into it: it refuses the same
 *     restyling and impersonating elements and attributes as the untrusted
 *     profiles (`style`, `id`, `name`, forms and form controls), and keeps
 *     `class`, which a developer's own markup may use.
 *   - `svg`: a vector image the server generated (the 2FA QR code).
 *
 * Every profile refuses every `data-*` attribute outside its own list, and
 * every `data-pr-*` one in particular: the global tooltip, and any other
 * delegated listener, reads its behaviour off those attributes, which must
 * never come from record content. The untrusted profiles also drop what lets
 * content restyle or impersonate the panel around it: the `style` attribute
 * and element, form controls and the attributes that submit a form, and `id`
 * / `name` (a value that collides with an id the app looks up). `class` is
 * dropped from the untrusted profiles except the few classes legitimate
 * content carries (a Trix attachment's, a fenced code block's language): the
 * stylesheet defines every utility class, so a `fixed inset-0 z-50` on a
 * `div` would paint a fake panel over the page. The only form control kept is
 * the disabled checkbox of a Markdown task list.
 */
export type HtmlProfile = 'richText' | 'markdown' | 'markup' | 'svg'

/** The attributes a Trix document carries around an attachment (see Trix's `AttachmentView`). */
const TRIX_ATTRIBUTES = ['data-trix-attachment', 'data-trix-content-type', 'data-trix-attributes']

/** Elements untrusted content has no use for, and that restyle or impersonate the page around it. */
const UNTRUSTED_FORBIDDEN_TAGS = [
  'style', 'form', 'button', 'select', 'textarea', 'option', 'optgroup',
  'label', 'fieldset', 'legend', 'datalist', 'output',
]

/** Attributes that restyle the page, submit a form or collide with an id the app looks up. */
const UNTRUSTED_FORBIDDEN_ATTRIBUTES = ['style', 'id', 'name', 'form', 'formaction', 'action']

const CONFIGS: Record<HtmlProfile, Config> = {
  richText: {
    ALLOW_DATA_ATTR: false,
    ADD_ATTR: TRIX_ATTRIBUTES,
    FORBID_TAGS: UNTRUSTED_FORBIDDEN_TAGS,
    FORBID_ATTR: UNTRUSTED_FORBIDDEN_ATTRIBUTES,
  },
  markdown: {
    ALLOW_DATA_ATTR: false,
    FORBID_TAGS: UNTRUSTED_FORBIDDEN_TAGS,
    FORBID_ATTR: UNTRUSTED_FORBIDDEN_ATTRIBUTES,
  },
  markup: {
    ALLOW_DATA_ATTR: false,
    FORBID_TAGS: UNTRUSTED_FORBIDDEN_TAGS,
    FORBID_ATTR: UNTRUSTED_FORBIDDEN_ATTRIBUTES,
    // A help text may open a link in a new tab (`target="_blank"`), which
    // DOMPurify drops by default: kept here, with `rel` forced below.
    ADD_ATTR: ['target'],
  },
  svg: {
    ALLOW_DATA_ATTR: false,
    USE_PROFILES: { svg: true },
  },
}

/**
 * The classes an untrusted profile keeps, by profile. Trix writes an
 * attachment as `figure.attachment.attachment--preview` (or `--file`, or
 * `--<extension>`) with `attachment__caption` / `__name` / `__size` parts,
 * and a gallery as `attachment-gallery attachment-gallery--<n>`; `marked`
 * writes `language-<name>` on the `code` of a fenced block. Nothing the
 * stylesheet gives layout (`fixed`, `absolute`, `z-*`, `inset-*`) matches.
 */
const ALLOWED_CLASS: Partial<Record<HtmlProfile, RegExp>> = {
  richText: /^attachment(?:-gallery)?(?:__[a-z0-9-]+|--[a-z0-9-]+)*$/,
  markdown: /^language-[A-Za-z0-9_+#.-]+$/,
}

/** Keep only the allow-listed classes of an untrusted profile (none for a profile with no list). */
function classFilterFor(profile: HtmlProfile): UponSanitizeAttributeHook | null {
  if (profile !== 'richText' && profile !== 'markdown') return null
  const allowed = ALLOWED_CLASS[profile] as RegExp

  return (_node, event) => {
    if (event.attrName !== 'class') return
    const kept = event.attrValue.split(/\s+/).filter((token) => allowed.test(token))
    if (kept.length === 0) {
      event.keepAttr = false
    } else {
      event.attrValue = kept.join(' ')
    }
  }
}

/**
 * The only `<input>` content may keep is the disabled checkbox of a Markdown
 * task list; every other input (a password field in a fake prompt) is
 * removed, and the checkbox loses every attribute but `type`, `checked` and
 * `disabled`.
 */
const restrictInputs: ElementHook = (node) => {
  if (node.tagName !== 'INPUT') return
  if ((node.getAttribute('type') ?? '').toLowerCase() !== 'checkbox') {
    node.remove()
    return
  }
  for (const name of node.getAttributeNames()) {
    if (name !== 'type' && name !== 'checked') node.removeAttribute(name)
  }
  node.setAttribute('disabled', '')
}

/** Drop every `data-pr-*` attribute, whatever allow-list let it in. */
const dropTooltipAttributes: UponSanitizeAttributeHook = (_node, event) => {
  if (event.attrName.startsWith('data-pr-')) {
    event.keepAttr = false
  }
}

/**
 * Whether the JSON of a `data-trix-attachment` attribute names only URLs a
 * person may be sent to. The display opens the `url` / `href` of the
 * attachment a click lands on, and the editor loads the rest of the
 * attributes back, so every member that is a URL (`url`, `href`,
 * `previewURL`, ...) must be a web or relative one. Anything that is not a
 * JSON object is refused.
 */
export function isSafeTrixAttachment(json: string): boolean {
  let parsed: unknown
  try {
    parsed = JSON.parse(json)
  } catch {
    return false
  }
  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return false

  for (const [key, value] of Object.entries(parsed as Record<string, unknown>)) {
    if (!/(url|href)$/i.test(key) || value === null || value === undefined || value === '') continue
    if (!isSafeNavigationUrl(value)) return false
  }

  return true
}

/** Drop a Trix attachment whose JSON carries an unsafe URL (see {@link isSafeTrixAttachment}). */
const validateTrixAttachment: UponSanitizeAttributeHook = (_node, event) => {
  if (event.attrName === 'data-trix-attachment' && !isSafeTrixAttachment(event.attrValue)) {
    event.keepAttr = false
  }
}

/** A link that opens a new tab never gets the opener (`rel="noopener noreferrer"`). */
const isolateNewTabLinks: ElementHook = (node) => {
  if (node.tagName === 'A' && node.hasAttribute('target')) {
    node.setAttribute('rel', 'noopener noreferrer')
  }
}

const instances: Partial<Record<HtmlProfile, DOMPurify>> = {}

/** One DOMPurify instance per profile, built on first use: its hooks are the profile's own. */
function purifierFor(profile: HtmlProfile): DOMPurify {
  const existing = instances[profile]
  if (existing !== undefined) return existing

  const purify = createDOMPurify(window)
  purify.addHook('uponSanitizeAttribute', dropTooltipAttributes)
  if (profile === 'richText') {
    purify.addHook('uponSanitizeAttribute', validateTrixAttachment)
  }
  const classFilter = classFilterFor(profile)
  if (classFilter !== null) {
    purify.addHook('uponSanitizeAttribute', classFilter)
  }
  if (profile !== 'svg') {
    purify.addHook('afterSanitizeAttributes', restrictInputs)
  }
  if (profile === 'markup') {
    purify.addHook('afterSanitizeAttributes', isolateNewTabLinks)
  }
  instances[profile] = purify

  return purify
}

/** Sanitise `html` with a profile (see {@link HtmlProfile}). */
export function sanitizeHtml(html: string, profile: HtmlProfile): string {
  return purifierFor(profile).sanitize(html, CONFIGS[profile])
}

/** A Trix document: untrusted, stored as raw HTML. */
export const sanitizeRichText = (html: string): string => sanitizeHtml(html, 'richText')

/** The HTML `marked` made of a Markdown field: untrusted. */
export const sanitizeMarkdownHtml = (html: string): string => sanitizeHtml(html, 'markdown')

/** A short inline fragment the application developer wrote (help text, HTML tooltip, gate message). */
export const sanitizeMarkup = (html: string): string => sanitizeHtml(html, 'markup')

/** A vector image the server generated. */
export const sanitizeSvg = (svg: string): string => sanitizeHtml(svg, 'svg')
