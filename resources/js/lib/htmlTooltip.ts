import type { RefCallback } from 'react'
import type { TooltipSide } from './tooltipPlacement'

/**
 * Which tooltip triggers may show HTML.
 *
 * The global tooltip (`MartisTooltip`) reads the body of whatever element the
 * pointer enters off its `data-pr-tooltip` attribute, so any markup in the
 * page can name a tooltip, record content included: a Markdown or Trix value
 * is rendered as HTML, and its elements carry whatever attributes survive
 * sanitising. The `data-pr-tooltip-html="true"` opt-in cannot be the whole
 * trust decision, as record content can write that attribute too. So an
 * element shows its tooltip as HTML only when the code that rendered it
 * registered the element here, and that is a property record content cannot
 * set: the registry holds the DOM nodes React created for a component, and a
 * node parsed out of an HTML string never gets there.
 *
 * A package component, or an extension, opts in with {@link htmlTooltip}
 * (spread onto the element) or {@link trustHtmlTooltip} (a ref callback, for
 * an element that already has a ref). The markup is sanitised on top of that
 * before it is rendered.
 */
const trusted = new WeakSet<Element>()

/** Ref callback that registers the element as a trigger whose tooltip may be HTML. */
export function trustHtmlTooltip(element: HTMLElement | null): void {
  if (element !== null) {
    trusted.add(element)
  }
}

/** Whether `element` was registered with {@link trustHtmlTooltip}. */
export function isTrustedHtmlTooltip(element: Element): boolean {
  return trusted.has(element)
}

/** The props {@link htmlTooltip} returns, spread onto the trigger element. */
export interface HtmlTooltipProps {
  'data-pr-tooltip': string
  'data-pr-tooltip-html': 'true'
  'data-pr-position'?: TooltipSide
  ref: RefCallback<HTMLElement>
}

/**
 * Props that make an element a tooltip trigger whose body is HTML (line
 * breaks, bold, lists, links) written by the application, never by a user:
 *
 *     <span {...htmlTooltip('<strong>Re-index</strong><br/>Rebuilds the index.', 'top')}>
 *       <InfoIcon size={14} />
 *     </span>
 *
 * The markup is sanitised before it is shown, but keep record data out of it.
 * An element that has a ref of its own registers it with
 * {@link trustHtmlTooltip} instead and sets the three `data-pr-*` attributes
 * itself.
 */
export function htmlTooltip(markup: string, position?: TooltipSide): HtmlTooltipProps {
  return {
    'data-pr-tooltip': markup,
    'data-pr-tooltip-html': 'true',
    ...(position !== undefined ? { 'data-pr-position': position } : {}),
    ref: trustHtmlTooltip,
  }
}
