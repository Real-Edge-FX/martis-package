# Audit findings rejected after verification (false positives)

The adversarial verifier is not infallible. Each finding is re-checked against
the real code before any fix. Findings rejected here must NOT be "fixed" —
fixing them would introduce a regression.

## CRITICAL — Impersonation `stop()` "missing authorization check"

- **File:** `src/Http/Controllers/ImpersonationController.php:98-107`
- **Verdict: FALSE POSITIVE. Do not add a gate to `stop()`.**
- **Why:** `stop()` restores the operator from the id stashed in the session by
  `start()` (`ImpersonationManager::stop()` reads `$stashed['original']`, never
  request input). There is no escalation: it can only undo a session that
  `start()` legitimately created (and `start()` enforces the `martis-impersonate`
  gate + the `NotImpersonable` opt-out). Requiring the gate on `stop()` would be
  a real bug — during impersonation the effective user IS the (unprivileged)
  target, who fails the gate, so the operator would be permanently trapped as
  the impersonated user. The absence of the gate on `stop()` is intentional and
  correct. A random authenticated user calling `stop()` with no active session
  hits the `isActive()` no-op guard.
