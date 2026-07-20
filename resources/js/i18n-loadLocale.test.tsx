import { describe, it, expect, beforeEach, afterEach, vi } from "vitest"
import i18n from "i18next"
import { loadLocale } from "@/lib/i18n"
import { queryClient } from "@/lib/query"
import { getSnapshot, endLocaleSwitch } from "@/lib/localeSwitching"

/*
 * `loadLocale()` is the single choke point every locale change funnels
 * through. It must raise the switching signal for the whole duration and
 * clear it in a `finally` — on success AND on failure — so the full-screen
 * overlay is never left stuck up. This also closes the pre-existing coverage
 * gap on `loadLocale`.
 */

function drain() {
  while (getSnapshot()) endLocaleSwitch()
}

beforeEach(() => {
  drain()
  vi.restoreAllMocks()
  vi.spyOn(i18n, "addResourceBundle").mockReturnValue(i18n)
  vi.spyOn(i18n, "changeLanguage").mockResolvedValue((() => "") as never)
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) }),
  )
})

afterEach(() => {
  drain()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe("loadLocale switching signal", () => {
  it("holds the signal true for the whole switch and clears it after", async () => {
    // Capture the signal at the final async phase (invalidateQueries) to prove
    // it was not cleared early, without racing microtasks.
    let signalDuringInvalidate: boolean | null = null
    vi.spyOn(queryClient, "invalidateQueries").mockImplementation(async () => {
      signalDuringInvalidate = getSnapshot()
    })

    expect(getSnapshot()).toBe(false)

    const pending = loadLocale("pt_PT")
    // Raised synchronously, before the first await settles.
    expect(getSnapshot()).toBe(true)

    await pending
    expect(signalDuringInvalidate).toBe(true) // still up during the last phase
    expect(getSnapshot()).toBe(false) // cleared in the finally
  })

  it("clears the signal even when the switch throws", async () => {
    vi.spyOn(queryClient, "invalidateQueries").mockResolvedValue(undefined)
    vi.spyOn(i18n, "changeLanguage").mockRejectedValue(new Error("boom"))

    await expect(loadLocale("pt_PT")).rejects.toThrow("boom")
    expect(getSnapshot()).toBe(false)
  })
})
