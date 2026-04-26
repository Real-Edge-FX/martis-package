import { lazy, Suspense, createElement, type ComponentType } from 'react'
import type { IconProps } from '@phosphor-icons/react'

import {
  ActivityIcon,
  AddressBookIcon,
  ArchiveIcon,
  ArrowClockwiseIcon,
  ArrowCounterClockwiseIcon,
  ArrowDownRightIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  ArrowSquareOutIcon,
  ArrowUpRightIcon,
  ArrowsClockwiseIcon,
  BellIcon,
  BookOpenIcon,
  BriefcaseIcon,
  BuildingsIcon,
  CalendarIcon,
  CameraIcon,
  CaretDownIcon,
  CaretRightIcon,
  CaretUpIcon,
  ChartBarIcon,
  ChartLineUpIcon,
  ChartPieIcon,
  ChatCircleIcon,
  CheckCircleIcon,
  CheckIcon,
  CircleIcon,
  ClipboardIcon,
  ClipboardTextIcon,
  CodeIcon,
  CompassIcon,
  CopyIcon,
  CreditCardIcon,
  CrosshairIcon,
  CurrencyEurIcon,
  DatabaseIcon,
  DotsSixVerticalIcon,
  DotsThreeIcon,
  DownloadSimpleIcon,
  EnvelopeIcon,
  EnvelopeSimpleIcon,
  EyeIcon,
  EyeSlashIcon,
  FileCodeIcon,
  FileCsvIcon,
  FileDocIcon,
  FileIcon,
  FilePdfIcon,
  FilePptIcon,
  FileTextIcon,
  FileXlsIcon,
  FileZipIcon,
  FlagBannerIcon,
  FloppyDiskIcon,
  FolderIcon,
  FolderSimpleIcon,
  FunnelIcon,
  GearIcon,
  GearSixIcon,
  GlobeHemisphereEastIcon,
  GlobeIcon,
  HeartIcon,
  HouseIcon,
  ImageIcon,
  ImagesSquareIcon,
  InfoIcon,
  KeyIcon,
  LightningIcon,
  LinkBreakIcon,
  LinkSimpleIcon,
  ListIcon,
  LockIcon,
  LockSimpleIcon,
  MagnifyingGlassIcon,
  MapPinIcon,
  MinusIcon,
  MonitorIcon,
  MoonIcon,
  MusicNoteIcon,
  PauseIcon,
  PencilSimpleIcon,
  PlayIcon,
  PlugsIcon,
  PlusIcon,
  ProhibitIcon,
  PulseIcon,
  QuestionIcon,
  RocketLaunchIcon,
  ShieldCheckIcon,
  ShieldSlashIcon,
  ShoppingCartIcon,
  SlidersHorizontalIcon,
  SmileyBlankIcon,
  SparkleIcon,
  StackIcon,
  StarIcon,
  SunIcon,
  TagIcon,
  TargetIcon,
  TextAlignLeftIcon,
  ToggleRightIcon,
  TranslateIcon,
  TrashIcon,
  TrendUpIcon,
  UploadSimpleIcon,
  UserCircleIcon,
  UserGearIcon,
  UserIcon,
  UsersIcon,
  UsersThreeIcon,
  WarningCircleIcon,
  WarningIcon,
  WebhooksLogoIcon,
  XCircleIcon,
  XIcon,
} from '@phosphor-icons/react'

/**
 * Curated icon registry. Each kebab-case name maps to a Phosphor React
 * component. The set covers every icon used internally by the Martis
 * package plus the icons referenced by typical resource configs
 * (`->icon('rocket-launch')`, dashboard kickers, navigation entries).
 *
 * **Why curated and not `import * as`**:
 * pre-v0.8 the package imported the entire `@phosphor-icons/react`
 * namespace inside `ResourceIcon`, which defeated tree-shaking and
 * produced a ~5 MB minified `phosphor-icons-*.js` chunk on every
 * build. The curated set drops that to ~250 KB while still covering
 * the vast majority of consumer icon strings.
 *
 * **Extending from a consumer app**:
 * register additional icons at boot time:
 *
 * ```ts
 * import { iconRegistry } from '@martis/martis/lib/iconRegistry'
 * import { CrownIcon } from '@phosphor-icons/react'
 *
 * iconRegistry.register('crown', CrownIcon)
 * ```
 *
 * Names not found resolve to `DatabaseIcon` so the page never crashes.
 */
const builtIns: Record<string, ComponentType<IconProps>> = {
  activity: ActivityIcon,
  'address-book': AddressBookIcon,
  archive: ArchiveIcon,
  'arrow-clockwise': ArrowClockwiseIcon,
  'arrow-counter-clockwise': ArrowCounterClockwiseIcon,
  'arrow-down-right': ArrowDownRightIcon,
  'arrow-left': ArrowLeftIcon,
  'arrow-right': ArrowRightIcon,
  'arrow-square-out': ArrowSquareOutIcon,
  'arrow-up-right': ArrowUpRightIcon,
  'arrows-clockwise': ArrowsClockwiseIcon,
  bell: BellIcon,
  'book-open': BookOpenIcon,
  briefcase: BriefcaseIcon,
  buildings: BuildingsIcon,
  calendar: CalendarIcon,
  camera: CameraIcon,
  'caret-down': CaretDownIcon,
  'caret-right': CaretRightIcon,
  'caret-up': CaretUpIcon,
  'chart-bar': ChartBarIcon,
  'chart-line-up': ChartLineUpIcon,
  'chart-pie': ChartPieIcon,
  chat: ChatCircleIcon,
  'chat-circle': ChatCircleIcon,
  check: CheckIcon,
  'check-circle': CheckCircleIcon,
  circle: CircleIcon,
  clipboard: ClipboardIcon,
  'clipboard-text': ClipboardTextIcon,
  code: CodeIcon,
  compass: CompassIcon,
  copy: CopyIcon,
  'credit-card': CreditCardIcon,
  crosshair: CrosshairIcon,
  'currency-eur': CurrencyEurIcon,
  database: DatabaseIcon,
  'dots-six-vertical': DotsSixVerticalIcon,
  'dots-three': DotsThreeIcon,
  'download-simple': DownloadSimpleIcon,
  envelope: EnvelopeIcon,
  'envelope-simple': EnvelopeSimpleIcon,
  eye: EyeIcon,
  'eye-slash': EyeSlashIcon,
  file: FileIcon,
  'file-code': FileCodeIcon,
  'file-csv': FileCsvIcon,
  'file-doc': FileDocIcon,
  'file-pdf': FilePdfIcon,
  'file-ppt': FilePptIcon,
  'file-text': FileTextIcon,
  'file-xls': FileXlsIcon,
  'file-zip': FileZipIcon,
  'flag-banner': FlagBannerIcon,
  'floppy-disk': FloppyDiskIcon,
  folder: FolderIcon,
  'folder-simple': FolderSimpleIcon,
  folders: FolderSimpleIcon,
  funnel: FunnelIcon,
  gear: GearIcon,
  'gear-six': GearSixIcon,
  globe: GlobeIcon,
  'globe-hemisphere-east': GlobeHemisphereEastIcon,
  heart: HeartIcon,
  house: HouseIcon,
  image: ImageIcon,
  'images-square': ImagesSquareIcon,
  info: InfoIcon,
  key: KeyIcon,
  lightning: LightningIcon,
  'link-break': LinkBreakIcon,
  'link-simple': LinkSimpleIcon,
  list: ListIcon,
  lock: LockIcon,
  'lock-simple': LockSimpleIcon,
  'magnifying-glass': MagnifyingGlassIcon,
  'map-pin': MapPinIcon,
  minus: MinusIcon,
  monitor: MonitorIcon,
  moon: MoonIcon,
  'music-note': MusicNoteIcon,
  pause: PauseIcon,
  'pencil-simple': PencilSimpleIcon,
  play: PlayIcon,
  plugs: PlugsIcon,
  plus: PlusIcon,
  prohibit: ProhibitIcon,
  pulse: PulseIcon,
  question: QuestionIcon,
  'rocket-launch': RocketLaunchIcon,
  'shield-check': ShieldCheckIcon,
  'shield-slash': ShieldSlashIcon,
  'shopping-cart': ShoppingCartIcon,
  'sliders-horizontal': SlidersHorizontalIcon,
  'smiley-blank': SmileyBlankIcon,
  sparkle: SparkleIcon,
  stack: StackIcon,
  star: StarIcon,
  sun: SunIcon,
  tag: TagIcon,
  target: TargetIcon,
  'text-align-left': TextAlignLeftIcon,
  'toggle-right': ToggleRightIcon,
  translate: TranslateIcon,
  trash: TrashIcon,
  'trend-up': TrendUpIcon,
  'upload-simple': UploadSimpleIcon,
  user: UserIcon,
  'user-circle': UserCircleIcon,
  'user-gear': UserGearIcon,
  users: UsersIcon,
  'users-three': UsersThreeIcon,
  warning: WarningIcon,
  'warning-circle': WarningCircleIcon,
  'webhooks-logo': WebhooksLogoIcon,
  x: XIcon,
  'x-circle': XCircleIcon,
}

/**
 * Normalise an incoming icon name to the kebab-case key used by the
 * registry. Accepts kebab-case (`shopping-cart`), PascalCase
 * (`ShoppingCart`), snake_case (`shopping_cart`), or any of the
 * above suffixed with `Icon`.
 */
function normalise(name: string): string {
  return name
    .replace(/Icon$/, '')
    .replace(/([A-Z])/g, '-$1')
    .replace(/^-/, '')
    .replace(/_/g, '-')
    .toLowerCase()
}

/** kebab-case → PascalCase. `shopping-cart` → `ShoppingCart`. */
function pascalize(kebab: string): string {
  return kebab
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

/**
 * Per-icon loader map. `import.meta.glob` enumerates every CSR icon
 * file at build time and emits one chunk per file (~5-15 KB each).
 * Only the icons actually requested at runtime ever ship to the
 * browser. The eager array `eagerlyImported` above stays the synchronous
 * fast path; this glob handles the remaining 1380+ icons that consumer
 * apps reference by name without paying a 5 MB up-front cost.
 *
 * Keys look like:
 *   `/node_modules/@phosphor-icons/react/dist/csr/Database.es.js`
 *
 * The map is consulted lazily — referencing `iconLoaders` at module
 * init does NOT trigger any network request.
 */
const iconLoaders = import.meta.glob<{ [key: string]: ComponentType<IconProps> | undefined }>(
  '/node_modules/@phosphor-icons/react/dist/csr/*.es.js',
)

const dynamicCache = new Map<string, ComponentType<IconProps>>()

function buildLazyIcon(pascal: string): ComponentType<IconProps> {
  const path = `/node_modules/@phosphor-icons/react/dist/csr/${pascal}.es.js`
  const loader = iconLoaders[path]

  // Pascal name didn't match a Phosphor file — short-circuit to the
  // synchronous fallback so the page never blocks waiting for an
  // import that will never resolve.
  if (!loader) return DatabaseIcon

  const Lazy = lazy(async () => {
    try {
      const module = await loader()
      const Component = (module[`${pascal}Icon`] ?? module[pascal]) as ComponentType<IconProps> | undefined
      return { default: Component ?? DatabaseIcon }
    } catch {
      return { default: DatabaseIcon }
    }
  })

  const Wrapped: ComponentType<IconProps> = (props) =>
    createElement(
      Suspense,
      { fallback: null },
      createElement(Lazy, props as Parameters<typeof Lazy>[0]),
    )
  Wrapped.displayName = `Lazy${pascal}Icon`
  return Wrapped
}

class IconRegistry {
  private readonly custom = new Map<string, ComponentType<IconProps>>()

  /**
   * Register a custom icon under a kebab-case name. Overrides any
   * built-in entry with the same name. Use this from a consumer
   * boot file when you want a synchronous, eagerly-bundled icon
   * instead of waiting for the dynamic-import fallback to load.
   */
  register(name: string, component: ComponentType<IconProps>): void {
    this.custom.set(normalise(name), component)
  }

  /**
   * Resolve an icon by name. Three-tier strategy:
   *
   *   1. **Custom registry** — consumer-registered icons win.
   *   2. **Curated built-ins** — 130 most-used Phosphor icons,
   *      bundled eagerly. Synchronous resolution, no async render.
   *   3. **Dynamic CSR import** — every other Phosphor icon (1380+
   *      of them) loads on demand from
   *      `@phosphor-icons/react/dist/csr/<Name>.es.js`. Each becomes
   *      its own ~5–15 KB chunk; only the icons actually requested
   *      at runtime ever ship to the browser.
   *
   * Names that don't match any Phosphor export fall back silently
   * to `DatabaseIcon` so the page never crashes on a typo.
   */
  resolve(name: string | null | undefined): ComponentType<IconProps> {
    if (!name) return DatabaseIcon
    const key = normalise(name)

    const customMatch = this.custom.get(key)
    if (customMatch) return customMatch

    if (key in builtIns) return builtIns[key]

    const cached = dynamicCache.get(key)
    if (cached) return cached

    const pascal = pascalize(key)
    const lazyIcon = buildLazyIcon(pascal)
    dynamicCache.set(key, lazyIcon)
    return lazyIcon
  }

  /**
   * Returns true when the given name is in the eagerly-bundled set
   * (custom or curated). Names served by the dynamic-import
   * fallback return `false` here even though they DO render — the
   * method is intended for "do I need to wait for a network round
   * trip" decisions, not "does this icon exist".
   */
  has(name: string | null | undefined): boolean {
    if (!name) return false
    const key = normalise(name)
    return this.custom.has(key) || key in builtIns
  }
}

/** Singleton instance — register custom icons against this. */
export const iconRegistry = new IconRegistry()
