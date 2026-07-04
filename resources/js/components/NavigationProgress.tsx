import { useEffect, useRef, useState } from "react"
import { useLocation } from "react-router-dom"
import { useIsFetching } from "@tanstack/react-query"

/*
 * Router-shell top progress bar.
 *
 * The router (`resources/js/router.tsx`) has no route `loader`s — every page
 * fetches its own data via react-query inside the component body — so
 * react-router's `useNavigation()` never leaves `'idle'` and cannot detect
 * these transitions. Instead this component is driven by two independent
 * signals:
 *
 *   1. `useLocation().pathname` changing -> BEGIN a navigation: show the bar
 *      and trickle its width toward ~80% (never finishing on its own, since
 *      we don't know how long the destination page's fetches will take).
 *   2. `useIsFetching()` (the global react-query in-flight counter) —
 *      while > 0 following a BEGIN we hold the trickle; once it settles back
 *      to 0 we COMPLETE the bar to 100% and then fade it out.
 *
 * A new pathname change arriving mid-run restarts the state machine cleanly
 * (back to a fresh trickle from 0).
 */

const TRICKLE_TARGET = 80
const TRICKLE_STEP_MS = 200
const TRICKLE_INCREMENT = 8
const FADE_OUT_MS = 400
const HOLD_AFTER_COMPLETE_MS = 150

type Phase = "idle" | "trickling" | "completing" | "fading"

export function NavigationProgress() {
  const location = useLocation()
  const isFetching = useIsFetching()

  const [phase, setPhase] = useState<Phase>("idle")
  const [width, setWidth] = useState(0)

  // Tracks whether a navigation is currently "in progress" (started by a
  // pathname change, not yet completed). Used to decide whether an
  // `isFetching === 0` reading should trigger completion.
  const runActiveRef = useRef(false)
  const timersRef = useRef<Array<ReturnType<typeof setTimeout>>>([])
  // Skip the BEGIN on the very first render — the initial mount's pathname
  // is the app loading, not a user-triggered navigation between pages.
  const isFirstRenderRef = useRef(true)

  const clearTimers = () => {
    timersRef.current.forEach((id) => clearTimeout(id))
    timersRef.current = []
  }

  const schedule = (fn: () => void, ms: number) => {
    const id = setTimeout(fn, ms)
    timersRef.current.push(id)
    return id
  }

  // BEGIN: a new pathname means a new navigation. Restart cleanly even if a
  // previous run was still in flight.
  useEffect(() => {
    if (isFirstRenderRef.current) {
      isFirstRenderRef.current = false
      return undefined
    }

    clearTimers()
    runActiveRef.current = true
    setPhase("trickling")
    setWidth(4)

    return () => {
      clearTimers()
    }
  }, [location.pathname])

  // Trickle: while trickling, creep width toward TRICKLE_TARGET without ever
  // reaching it on its own (only `isFetching` settling to 0 completes it).
  useEffect(() => {
    if (phase !== "trickling") return undefined

    const id = schedule(function tick() {
      setWidth((w) => {
        const next = Math.min(w + TRICKLE_INCREMENT, TRICKLE_TARGET)
        return next
      })
    }, TRICKLE_STEP_MS)

    return () => clearTimeout(id)
  }, [phase, width])

  // COMPLETE: once a run is active and fetching has settled back to 0, jump
  // to 100% and then fade out / reset to hidden.
  useEffect(() => {
    if (!runActiveRef.current) return
    if (phase !== "trickling") return
    if (isFetching > 0) return

    setPhase("completing")
    setWidth(100)

    schedule(() => {
      setPhase("fading")
    }, HOLD_AFTER_COMPLETE_MS)
  }, [isFetching, phase])

  // FADE: after fading begins, reset to idle/hidden.
  useEffect(() => {
    if (phase !== "fading") return undefined

    const id = schedule(() => {
      runActiveRef.current = false
      setPhase("idle")
      setWidth(0)
    }, FADE_OUT_MS)

    return () => clearTimeout(id)
  }, [phase])

  useEffect(() => () => clearTimers(), [])

  const active = phase === "trickling" || phase === "completing"
  const opacity = phase === "idle" ? 0 : phase === "fading" ? 0 : 1

  return (
    <div
      data-testid="nav-progress"
      data-active={active ? "true" : undefined}
      data-phase={phase}
      aria-hidden="true"
      style={{
        position: "fixed",
        top: 0,
        left: 0,
        right: 0,
        height: "3px",
        width: `${width}%`,
        backgroundColor: "var(--martis-accent)",
        zIndex: 9999,
        pointerEvents: "none",
        opacity,
        transition:
          phase === "fading"
            ? `opacity ${FADE_OUT_MS}ms ease-out`
            : "width 200ms ease-out",
      }}
    />
  )
}
