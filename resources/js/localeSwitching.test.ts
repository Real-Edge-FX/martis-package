import { describe, it, expect, beforeEach, afterEach } from "vitest"
import {
  beginLocaleSwitch,
  endLocaleSwitch,
  getSnapshot,
  subscribe,
} from "@/lib/localeSwitching"

// The store is module-global; drain it to zero around every test so state
// never leaks between cases (or from other suites sharing the module).
function drain() {
  while (getSnapshot()) endLocaleSwitch()
}

beforeEach(drain)
afterEach(drain)

describe("localeSwitching store", () => {
  it("starts idle", () => {
    expect(getSnapshot()).toBe(false)
  })

  it("flips true while a switch is active and false after it ends", () => {
    beginLocaleSwitch()
    expect(getSnapshot()).toBe(true)
    endLocaleSwitch()
    expect(getSnapshot()).toBe(false)
  })

  it("balances overlapping switches — stays true until the last end", () => {
    beginLocaleSwitch()
    beginLocaleSwitch()
    expect(getSnapshot()).toBe(true)
    endLocaleSwitch()
    expect(getSnapshot()).toBe(true) // one still in flight
    endLocaleSwitch()
    expect(getSnapshot()).toBe(false)
  })

  it("never goes negative — a stray end() is a no-op", () => {
    endLocaleSwitch()
    expect(getSnapshot()).toBe(false)
    beginLocaleSwitch()
    endLocaleSwitch()
    endLocaleSwitch() // extra
    expect(getSnapshot()).toBe(false)
  })

  it("notifies subscribers on the false→true and true→false transitions only", () => {
    let hits = 0
    const unsub = subscribe(() => {
      hits += 1
    })

    beginLocaleSwitch() // 0 -> 1 : emit
    beginLocaleSwitch() // 1 -> 2 : no emit (already true)
    endLocaleSwitch() //   2 -> 1 : no emit (still true)
    endLocaleSwitch() //   1 -> 0 : emit
    expect(hits).toBe(2)

    unsub()
    beginLocaleSwitch()
    endLocaleSwitch()
    expect(hits).toBe(2) // no notifications after unsubscribe
  })
})
