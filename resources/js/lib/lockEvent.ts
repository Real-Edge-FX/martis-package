/**
 * The window event the API client raises when a data endpoint answers 403
 * with the lock payload of a soft-locked entity (`{ locked: true, lock }`,
 * `SoftGate::refusal()`, v2.4.0). `<GateProvider>` opens the lock modal on
 * it, so a user who lands on a locked entity's URL is shown the upsell the
 * sidebar shows, not a bare error. `detail` is the `GateLock`.
 */
export const LOCKED_EVENT = 'martis:locked'
