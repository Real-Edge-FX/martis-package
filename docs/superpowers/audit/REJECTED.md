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

## MEDIUM — "ActionController returns 404 instead of 403 for authorization failures"

- **File:** `src/Http/Controllers/ActionController.php:157,163,167` (per-model authz in `execute()`)
- **Verdict: REJECTED (intentional, tested behaviour).** Do not change these to 403.
- **Why:** The codebase deliberately returns 404 (not 403) for per-model action
  authorization failures, as an anti-enumeration measure — and this is pinned by
  `tests/Feature/ActionControllerTest.php` (multiple `assertStatus(404)` cases for
  denied actions). Flipping to 403 would break those tests and reverse a deliberate
  "don't reveal which records exist / why the action was refused" choice. The parent-
  level authz uses 403, but the per-model loop intentionally uses 404. If a future
  decision wants 403 everywhere, that is a product change (update tests + docs), not
  a bug fix.
