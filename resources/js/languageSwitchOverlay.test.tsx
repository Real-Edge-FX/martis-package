import { describe, it, expect, beforeEach, afterEach, vi } from "vitest"
import { act, render, screen, cleanup } from "@testing-library/react"
import { LanguageSwitchOverlay } from "@/components/LanguageSwitchOverlay/LanguageSwitchOverlay"
import {
  beginLocaleSwitch,
  endLocaleSwitch,
  getSnapshot,
} from "@/lib/localeSwitching"

function drain() {
  while (getSnapshot()) endLocaleSwitch()
}

beforeEach(() => {
  vi.useFakeTimers()
  drain()
})

afterEach(() => {
  drain()
  cleanup()
  vi.runOnlyPendingTimers()
  vi.useRealTimers()
})

describe("LanguageSwitchOverlay", () => {
  it("renders nothing while idle", () => {
    render(<LanguageSwitchOverlay />)
    expect(screen.queryByRole("status")).toBeNull()
  })

  it("shows a blocking status overlay with the message while switching", () => {
    render(<LanguageSwitchOverlay />)

    act(() => {
      beginLocaleSwitch()
    })

    const status = screen.getByRole("status")
    expect(status.getAttribute("aria-busy")).toBe("true")
    expect(status.getAttribute("aria-live")).toBe("polite")
    // Message falls back to the English default when the key is absent from the
    // test i18n resources — that is the real `t(key, default)` behaviour.
    expect(screen.getByText("Switching language…")).toBeTruthy()
  })

  it("dismisses after the switch ends and the min-visible window passes", () => {
    render(<LanguageSwitchOverlay />)

    act(() => {
      beginLocaleSwitch()
    })
    expect(screen.queryByRole("status")).not.toBeNull()

    act(() => {
      endLocaleSwitch()
    })
    // Still up inside the 400ms floor.
    act(() => {
      vi.advanceTimersByTime(200)
    })
    expect(screen.queryByRole("status")).not.toBeNull()

    act(() => {
      vi.advanceTimersByTime(300) // total 500ms > 400ms floor
    })
    expect(screen.queryByRole("status")).toBeNull()
  })
})
