import { describe, it, expect, beforeEach, afterEach } from "vitest"
import { applyDocumentDirection } from "@/lib/i18n"

// `config` is read live from `window.MartisConfig`. Stash + restore
// around each test so we can drive the rtlLocales list deterministically.
let originalConfig: unknown

beforeEach(() => {
  originalConfig = (window as unknown as { MartisConfig?: unknown }).MartisConfig
  ;(window as unknown as { MartisConfig?: unknown }).MartisConfig = {
    locales: { rtlLocales: ["ar", "he", "fa", "ur"] },
  }
  // Reset DOM attrs each test so cross-test state doesn't leak.
  document.documentElement.removeAttribute("dir")
  document.documentElement.removeAttribute("lang")
})

afterEach(() => {
  ;(window as unknown as { MartisConfig?: unknown }).MartisConfig = originalConfig
  document.documentElement.removeAttribute("dir")
  document.documentElement.removeAttribute("lang")
})

describe("applyDocumentDirection", () => {
  it("sets dir=rtl on <html> when the locale is in rtlLocales", () => {
    applyDocumentDirection("ar")

    expect(document.documentElement.getAttribute("dir")).toBe("rtl")
    expect(document.documentElement.getAttribute("lang")).toBe("ar")
  })

  it("sets dir=ltr on <html> for non-RTL locales", () => {
    applyDocumentDirection("en")

    expect(document.documentElement.getAttribute("dir")).toBe("ltr")
    expect(document.documentElement.getAttribute("lang")).toBe("en")
  })

  it("matches case-insensitively", () => {
    applyDocumentDirection("AR")
    expect(document.documentElement.getAttribute("dir")).toBe("rtl")
  })

  it("works for hebrew, persian, and urdu", () => {
    for (const locale of ["he", "fa", "ur"]) {
      document.documentElement.removeAttribute("dir")
      applyDocumentDirection(locale)
      expect(document.documentElement.getAttribute("dir")).toBe("rtl")
    }
  })

  it("falls through to ltr when rtlLocales config is missing", () => {
    ;(window as unknown as { MartisConfig?: unknown }).MartisConfig = {}
    applyDocumentDirection("ar")
    expect(document.documentElement.getAttribute("dir")).toBe("ltr")
  })

  it("falls through to ltr when rtlLocales is an empty list", () => {
    ;(window as unknown as { MartisConfig?: unknown }).MartisConfig = {
      locales: { rtlLocales: [] },
    }
    applyDocumentDirection("ar")
    expect(document.documentElement.getAttribute("dir")).toBe("ltr")
  })
})
