# Martis Ecosystem Audit — durable state / resume map

**Last updated:** 2026-06-28 ~23:05 WEST
**Purpose:** Let a cold session continue this audit WITHOUT redoing any work. Read this first.

---

## The user's binding decisions (do not re-ask)

1. **Deliverable: fix EVERYTHING.** Not just a report, not just GitHub issues. Every confirmed finding gets fixed (TDD per fix + docs sync), accumulated into a release. (This overrides the default `feedback_bug_handling_workflow` "issues-not-PRs" rule — the user explicitly chose "corrigir tudo, não quero problemas".)
2. **Scope: martis-package + docs only.** PHP + React + `docs/*.md` + the `martis-docs` mirror (`src/content/**/*.mdx`). NOT martis-playground, NOT consumers (edge-flow is Python now).
3. **Hold the v1.15.2 tag.** Do not tag/release yet — accumulate the audit fixes into a larger release. PR [#193](https://github.com/Real-Edge-FX/martis-package/pull/193) stays OPEN, NOT merged, NOT tagged.
4. **Docs sync is a hard rule.** Package `docs/*.md` ↔ `martis-docs` `*.mdx` must be EQUAL and reflect the code (source of truth = martis-package). Covers create/edit/delete/rename. Doc drift counts as a bug. Every landing-pill bump also updates `RELEASE_HEADLINE` in `martis-docs/src/data/landing.ts`.

---

## Phase plan

- **Phase 1 — FIND + VERIFY (workflow):** IN PROGRESS. See "Workflow" below.
- **Phase 2 — Triage + fix plan:** read `findings.json`, group by severity/subsystem, separate safe one-liners from entangled work, build an SDD plan, present consolidated report to user.
- **Phase 3 — Fix everything (SDD):** on this branch (`audit/ecosystem-sweep-2026-06-28`), each finding → failing test → fix → docs sync (both sides) → green. Whole-branch review at the end. Then ONE larger release.

---

## Workflow (Phase 1) — HOW TO RESUME WITHOUT RE-BURNING

The audit runs as a background Workflow. **Resuming uses the on-disk journal — completed agents return cached results instantly at ZERO token cost.** Only failed/new agents re-run. Do NOT author a new workflow from scratch.

- **Run ID:** `wf_5b41df2f-74d`
- **Script path:** `/Users/lmoura/.claude/projects/-Users-lmoura-projects-martis-martis-package/8c99f15c-6537-4b99-a9db-8f2073119f8e/workflows/scripts/martis-ecosystem-audit-wf_5b41df2f-74d.js`
- **Transcript/journal dir (durable on disk):** `/Users/lmoura/.claude/projects/-Users-lmoura-projects-martis/8c99f15c-6537-4b99-a9db-8f2073119f8e/subagents/workflows/wf_5b41df2f-74d/`
- **To resume:** `Workflow({ scriptPath: "<script path above>", resumeFromRunId: "wf_5b41df2f-74d" })`

**What it does:** 30 targets (21 PHP subsystems + 3 frontend areas + 3 security lenses + 2 docs-sync + 1 fe-pages-lib) → finder (code-analyzer, sonnet) → adversarial per-finding verifier → synthesizer (opus) writes the report.

**The synthesizer writes (EPHEMERAL /tmp — must be copied to git on completion):**
- `<scratch>/audit/AUDIT-REPORT.md`
- `<scratch>/audit/findings.json`
- scratch = `/private/tmp/claude-72892903/-Users-lmoura-projects-martis/8c99f15c-6537-4b99-a9db-8f2073119f8e/scratchpad/audit/`

**ON COMPLETION, immediately copy both into this repo and commit/push:**
`cp <scratch>/audit/*.{md,json} docs/superpowers/audit/ && git add docs/superpowers/audit && git commit && git push`
(The /tmp scratch dies with the session — this copy is what makes the findings durable.)

### OUTCOME (2026-06-28 ~23:40): Phase 1 COMPLETE — findings recovered + durable

The run-2 synth agent stalled (wrote only a stray `severity_tally.txt`); `findings.json`/`AUDIT-REPORT.md` were never produced in /tmp. **Recovered the full confirmed-findings array from the synth agent's transcript** (`agent-a11e9c1339cb280ba.jsonl` — the workflow had passed `JSON.stringify(confirmed)` into the synth prompt), zero extra agent tokens. Generated the consolidated report locally.

**Durable artifacts (committed, branch `audit/ecosystem-sweep-2026-06-28`):**
- `docs/superpowers/audit/findings.json` — 159 raw confirmed findings.
- `docs/superpowers/audit/AUDIT-REPORT.md` — 158 actionable (1 verifier-invalidated dropped), severity-ranked.

**Totals:** critical 3 · high 28 · medium 57 · low 70. Categories: security 52 · bug 54 · doc-drift 20 · implementation 14 · convention 18 · perf 1.

**3 criticals:** (1) pivot action execution skips per-model canRun + resource authz; (2) impersonation `stop()` endpoint missing authz; (3) MarkdownField unsanitized `marked.parse()` → `dangerouslySetInnerHTML` (XSS). Workflow run is gone (TaskStop reports no such task) — no zombie. Phase 3 = fix all (dedupe the 2 already-[FIXED]).

### PHASE 3 — fixing in progress (2026-06-28/29)

Inline security fixes already committed + pushed on `audit/ecosystem-sweep-2026-06-28`:
- `e710016` MarkdownField stored XSS (critical, DOMPurify)
- `bc9ac7e` pivot action authz: parent view + per-model canRun (critical)
- `409277` Url field javascript:/data: XSS allowlist (high, FE+BE)
- `7c25ef8` Lens cache cross-user leak: user-scoped key (high)
- (+ prior `d28121e` sort whitelist, `ced5c50` TrendMetric cross-driver)

REJECTED (false positive, do NOT fix): impersonation `stop()` authz — see REJECTED.md.

**Parallel tail fix workflow:** run `wf_4f1bdbd4-e1b` (task wjh5leyhn). 23 worktree-isolated agents, one per non-security subsystem batch (96 tail findings total; the 2 docs-sync batches excluded — handled separately because they span the martis-docs repo). Each agent verifies-then-fixes-or-rejects, writes a patch to `<scratch>/audit-patches/<key>.patch` + a report `<key>.report.json`, does NOT commit/CHANGELOG/build. Batch findings live at `<scratch>/audit-batches/<key>.json`.
- **On completion:** `git apply` each patch onto the audit branch (file-disjoint → clean), assemble CHANGELOG from the report `changelogLines`, run full pest+vitest+phpstan+pint, rebuild public/ if any FE batch changed, commit.
- Scratch (EPHEMERAL): `/private/tmp/claude-72892903/-Users-lmoura-projects-martis/8c99f15c-6537-4b99-a9db-8f2073119f8e/scratchpad/audit-patches/`. If the session dies before applying, the patches are lost — re-run the tail workflow (`scriptPath` in the workflows dir, the keys are hardcoded now).

Remaining inline (mine): ~18 security/high findings (SSO deny-bypass, 2FA guards, mass-assignment, config dup key, checkPolicy ignores $request, InstallCommand --force, UserCommand plaintext pw, etc.) + the 2 docs-sync batches.

#### TAIL WORKFLOW — FULLY APPLIED (2026-06-29)

All 23 tail batches landed in 2 commits: `5cb4d6f` (16 batches, 54 fixes) + `24fcbf0` (7 batches, 22 fixes) = **76 tail fixes**. Patches were applied with `.claude-flow` scratch-noise stripped (the agents' `git add -A` swept it in) and a few reject hunks resolved by hand. Fallout fixed during merge: KeyValue i18n (reverted — needs lang keys + translator-booted tests; DEFERRED), UserCommandTest (rewritten to the project's per-suite schema-bootstrap convention), TwoFactorService phpstan (@phpstan-assert on requireModel + property @var), MartisServiceProvider Octane events as strings (optional dep), ProfileResource non-package class ref, ParitySurfaceTest enum caller, EnvFilePatcherTest dotfile-safe teardown.

**Verify-pass rejections/deferrals (do NOT blindly re-apply):** window.confirm→DeleteModal (needs controlled state), DrawerSlot::Quick orphan (needs FE consumer), HasPolicy self::→static:: policy cache (latent test-isolation hazard, no prod impact), KeyValue i18n default labels.

Test health after tail: **2107 Pest + 184 Vitest passing, phpstan clean, pint clean.** Worktrees cleaned (`git worktree prune`). Frontend bundle rebuild STILL deferred to pre-release.

**Next: inline security highs** (the ~18 above) then the 2 docs-sync batches. The v1.15.2 tag remains HELD.

### Run history

- **Run 1 (task wlks2dsru):** hit session limit (resets 23:00 Lisbon) mid-way. Returned `{confirmed:44, critical:2, high:14, medium:15, low:13}` but `synth: null` — REPORT NOT WRITTEN. ~130 verify agents + fe-pages-lib finder + synth failed on the limit. ~227 agents / 5.8M tokens already cached on disk.
- **Run 2 (resume, task wzg3jb465):** launched 23:01 after limit reset. Re-runs only the failed tail (cached prefix free). Should write the report + findings.json.

---

## Already FIXED — do NOT re-investigate or re-fix (committed + pushed)

On branch `fix/metric-controller-resource-registry-v1.15.2` (PR #193, HELD):
1. `MetricController::computeResourceMetric()` — `ResourceRegistry::resolve()` (nonexistent) → `has()`-guard + `get()`. Resource metric cards were 500ing.
2. `TrendResult` sparkline "null%" — omit `change` when delta is null; TSX `TrendCard` guards `change != null`.
3. `ValueResult` negative-baseline — change guard `> 0` → `!= 0`.
Tests: 2048 Pest passing. New: `MetricControllerResourceCardTest`, 3 cases in `MetricTest`.

These are also independently shipped as the v1.15.2 line (held). The audit will surface MORE metric findings (e.g. metric cache-key user-scoping) — those are NOT yet fixed.

### Audit Phase-3 fixes already landed on THIS branch (`audit/ecosystem-sweep-2026-06-28`)

1. **`?sort=` whitelist on BelongsToMany + MorphToMany** (commit `d28121eae`). Added shared `MartisController::isSortableAttribute()`; both controllers now gate `orderBy()` on the related resource's declared sortable fields (was: raw param → orderBy, 500 on MySQL/Postgres for bogus columns, undeclared real columns honoured). Tests in both controller suites. No doc change (already matched `docs/relationships.md`).
2. **`TrendMetric` cross-driver DB aggregation** (commit `ced5c5055`). Was MySQL-only `DATE_FORMAT()` → 500 on SQLite/Postgres. Reworked to PHP-side Carbon bucketing (database-agnostic, correct ISO-week). New `TrendMetricAggregationTest`. Docs: `docs/metrics.md` "Database support" note + mirrored to `martis-docs core/metrics.mdx` on branch `docs/audit-sweep-2026-06-28` (HELD, not deployed). User chose PHP-bucketing over per-driver SQL.

Full suite at 2055 Pest passing on this branch. Both fixes were forwarded by the user from the live audit before findings.json landed — when triaging findings.json, DEDUPE against these two (do not re-fix).

---

## Known high-value leads already surfaced (pre-verification, for context)

- **`DATE_FORMAT` is MySQL-only** in `TrendMetric::aggregateByPeriod()` / `PartitionMetric` → Trend & Partition metric DB aggregation 500s on Postgres/SQLite. Zero DB-level test coverage of that path. Entangled with the named-range `(int)"TODAY"→0` collapse. Needs per-driver SQL + a DB test harness. (LARGER, own task.)
- **Metric compute cache key not user-scoped** (`md5(uriKey_range_filters_locale)`) → user-scoped metrics can serve user A's value to user B. (Small fix, design tradeoff.)

---

## Open PRs / branches

- PR #193 `fix/metric-controller-resource-registry-v1.15.2` — v1.15.2 metric fixes, CI green, HELD (no tag).
- `audit/ecosystem-sweep-2026-06-28` (this branch) — durable home for audit state + findings + Phase 3 fixes.
