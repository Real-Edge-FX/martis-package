export const meta = {
  name: 'martis-ecosystem-audit',
  description: 'Exhaustive bug/security/impl/doc-drift audit of martis-package + martis-docs, adversarially verified',
  phases: [
    { title: 'Find', detail: 'parallel finders per subsystem + security lens + docs-sync' },
    { title: 'Verify', detail: 'adversarial per-finding verification, drop false positives' },
    { title: 'Synthesize', detail: 'consolidated severity-ranked report + machine-readable findings' },
  ],
}

const PKG = '/Users/lmoura/projects/martis/martis-package'
const DOCS = '/Users/lmoura/projects/martis/martis-docs'
const OUT = '/private/tmp/claude-72892903/-Users-lmoura-projects-martis/8c99f15c-6537-4b99-a9db-8f2073119f8e/scratchpad/audit'

const SHARED = `You are auditing the martis/martis Laravel admin-panel package (PHP 8.3+, Laravel 11/12/13, React 18 + TypeScript). The package at ${PKG} is the SOURCE OF TRUTH; ${DOCS} mirrors its docs publicly and the two doc sets must be equal and reflect the code.

Read the ACTUAL code with your tools (Read/Grep/Glob). Never speculate — every finding must quote the offending code with an exact file:line. If a file is clean on a dimension, do NOT invent a finding.

Hunt for:
- Correctness bugs: wrong logic, off-by-one, null/empty/zero edge cases, division by zero, type coercion, mutation-vs-immutability.
- CONTRACT DRIFT (high value — a real one was just found): calls to methods/properties that do not exist on the receiver (e.g. ResourceRegistry::resolve() which 500'd every metric card); PHP toArray() payload keys vs the TSX keys that read them; route -> handler signature mismatches; a JSON null passing a "!== undefined" guard and rendering "null%".
- Security: missing authorization / gate checks, IDOR, mass-assignment ($fillable/$guarded), SQL injection or driver-specific raw SQL (MySQL-only DATE_FORMAT breaks Postgres/SQLite), unvalidated input at boundaries, path traversal / unsafe file upload, SSRF on outbound HTTP, CSRF posture, secret/token handling (MCP bearer token, SSO tokens, 2FA secrets storage), info disclosure in error payloads.
- Implementation flaws: cross-driver portability (MySQL/Postgres/SQLite), cache-key correctness (missing user/tenant/range/filter scoping -> stale or leaked data), N+1, resource/handle leaks, signal/lifecycle bugs.
- Workspace-convention violations that are genuine defects: finite value sets not using PHP Enums; user-facing strings not using i18n translation keys; native HTML title= tooltips instead of the PrimeReact data-pr-tooltip pattern.

ALREADY FIXED (do NOT re-report): MetricController::computeResourceMetric resolve()->has()/get(); TrendResult sparkline "null%" (change omitted when null); ValueResult negative-previous change guard.`

const FINDINGS_SCHEMA = {
  type: 'object',
  required: ['subsystem', 'findings', 'coverageNote'],
  additionalProperties: false,
  properties: {
    subsystem: { type: 'string' },
    coverageNote: { type: 'string', description: 'What you read and whether you truncated' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        required: ['title', 'file', 'line', 'severity', 'category', 'evidence', 'suggestedFix', 'confidence'],
        additionalProperties: false,
        properties: {
          title: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'string' },
          severity: { enum: ['critical', 'high', 'medium', 'low'] },
          category: { enum: ['bug', 'security', 'implementation', 'doc-drift', 'perf', 'convention'] },
          evidence: { type: 'string', description: 'Quote the actual offending code' },
          suggestedFix: { type: 'string' },
          confidence: { enum: ['high', 'medium', 'low'] },
        },
      },
    },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  required: ['isReal', 'reasoning', 'adjustedSeverity'],
  additionalProperties: false,
  properties: {
    isReal: { type: 'boolean', description: 'true only if you confirmed it by reading the cited code' },
    reasoning: { type: 'string' },
    adjustedSeverity: { enum: ['critical', 'high', 'medium', 'low', 'invalid'] },
    fixNote: { type: 'string', description: 'Correction or nuance to the suggested fix, if any' },
  },
}

// key, label, paths (where to look), security (raises effort), focus hint
const TARGETS = [
  { key: 'fields-base-textlike', label: 'Fields: base + text-like', security: false, focus: 'Field.php base class + Text, Textarea, Email, Password, PasswordConfirmation, Number, Slug, Url, Heading, Hidden, Id. Validation rule building, fill/resolve, dependsOn, nullable handling.', paths: `${PKG}/src/Fields/Field.php and the listed text-like field classes under ${PKG}/src/Fields/` },
  { key: 'fields-relation', label: 'Fields: select + relations', security: false, focus: 'Select, MultiSelect, Tag, BelongsTo, BelongsToMany, HasOne, HasMany, MorphTo, MorphOne, MorphMany. Relatable query building, option loading, pivot handling, the synthetic /_/_/relatable endpoint contract.', paths: `the select/relation field classes under ${PKG}/src/Fields/` },
  { key: 'fields-media', label: 'Fields: file/media', security: true, focus: 'File, Image, Avatar, UiAvatar, Gravatar, Audio. UPLOAD VALIDATION (mime, size, extension), path traversal in stored filenames, disk/visibility, deleteStoredFile, thumbnail generation. This is a security-critical surface.', paths: `the file/media field classes under ${PKG}/src/Fields/` },
  { key: 'fields-special', label: 'Fields: special', security: false, focus: 'Code, Markdown (XSS in rendered markdown?), KeyValue, Color, Country, Currency, Date, DateTime, Timezone, Icon, Boolean, BooleanGroup, Status, Badge, Sparkline, Stack, Repeater. Serialization, formatting, timezone handling.', paths: `the special field classes under ${PKG}/src/Fields/` },
  { key: 'http-resource', label: 'Http: ResourceController', security: true, focus: 'CRUD: index/show/store/update/destroy/replicate/peek/schema. Authorization on every action, mass-assignment, IDOR, pagination/sort/search input validation, soft-delete handling.', paths: `${PKG}/src/Http/Controllers/ResourceController.php` },
  { key: 'http-relationships', label: 'Http: relationship controllers', security: true, focus: 'BelongsToManyController, HasOneController, HasManyController, MorphManyController. attach/detach/sync authorization, IDOR on related ids, relatable endpoint authz, pivot field mass-assignment.', paths: `the relationship controllers under ${PKG}/src/Http/Controllers/` },
  { key: 'http-auth-sso-ctrl', label: 'Http: auth/SSO/2FA controllers', security: true, focus: 'Login, register, password reset, email verify, 2FA challenge, SSO callback controllers. Rate limiting, token/secret handling, open-redirect on SSO callback, timing, CSRF, session fixation, 2FA bypass.', paths: `the auth/sso/registration/two-factor controllers under ${PKG}/src/Http/Controllers/ and middleware under ${PKG}/src/Http/Middleware/` },
  { key: 'http-misc', label: 'Http: remaining controllers + requests + middleware', security: true, focus: 'All remaining controllers, FormRequests, middleware, JSON resources/responses not covered by other targets. Authz gaps, input validation, error info disclosure.', paths: `${PKG}/src/Http/ excluding ResourceController, the relationship controllers, auth/sso, and MetricController` },
  { key: 'console', label: 'Console commands', security: true, focus: 'All 33 commands: install, generators (resource/field/action/etc), mcp-serve, agents, theme, vendor-publish, list-* . Path handling, file overwrite safety, --force semantics, EnvFilePatcher, stub resolution, the non-TTY confirm trap class of bug.', paths: `${PKG}/src/Console/` },
  { key: 'auth', label: 'Auth subsystem', security: true, focus: 'src/Auth: registration, default-registers-users, listeners (role change, authorization denial, impersonation recording). Privilege escalation, listener integrity.', paths: `${PKG}/src/Auth/` },
  { key: 'sso', label: 'SSO subsystem', security: true, focus: 'src/Sso: identity resolver, providers (Azure/Google/GitHub), role mapping. Token validation, state/nonce, account takeover via email matching, role-mapping injection.', paths: `${PKG}/src/Sso/` },
  { key: 'authz-gates-impersonation', label: 'Authorization + Gates + Impersonation', security: true, focus: 'src/Authorization, src/Gates, src/Impersonation. Gate resolution correctness, requirePlan fail-open vs fail-closed, impersonation start/stop authorization and audit, leaving impersonation.', paths: `${PKG}/src/Authorization/ ${PKG}/src/Gates/ ${PKG}/src/Impersonation/` },
  { key: 'cache', label: 'Cache subsystem', security: true, focus: 'src/Cache/MartisCache (551 lines). Version-key invalidation, kill-switch, bypass header, KEY SCOPING (do keys include user/tenant/locale/range/filters where the cached value depends on them? a missing dimension leaks or staleness), race conditions.', paths: `${PKG}/src/Cache/` },
  { key: 'metrics-rest', label: 'Metrics: remaining (beyond fixed)', security: true, focus: 'src/Metrics: TrendMetric/PartitionMetric DB aggregation (DATE_FORMAT is MySQL-only -> breaks Postgres/SQLite; column wrapping; named-range (int) cast collapse), Metric::resolve cache key user-scoping, ProgressResult/ActivityFeed/EndpointTable edge cases, uriKey derivation for non-ASCII. NOTE the 3 already-fixed bugs.', paths: `${PKG}/src/Metrics/ and ${PKG}/src/Http/Controllers/MetricController.php` },
  { key: 'actions-filters-lenses-cards', label: 'Actions/Filters/Lenses/Cards', security: true, focus: 'src/Actions (incl queued jobs, canRun authorization, ActionFields), src/Filters, src/Lenses, src/Cards. Action authorization per-model and bulk, filter input validation, lens query scoping, dangerous action confirmation.', paths: `${PKG}/src/Actions/ ${PKG}/src/Filters/ ${PKG}/src/Lenses/ ${PKG}/src/Cards/` },
  { key: 'menu-layout-dashboards', label: 'Menu/Layout/Dashboards', security: false, focus: 'src/Menu, src/Layout, src/Dashboards. Menu authorization filtering, dashboard resolve/authz, panel/section/tab building, badge endpoints.', paths: `${PKG}/src/Menu/ ${PKG}/src/Layout/ ${PKG}/src/Dashboards/` },
  { key: 'notif-pref-profile', label: 'Notifications/Preferences/Profile', security: true, focus: 'src/Notifications, src/Preferences, src/Profile. Preference persistence + reset, profile/avatar update authz, notification read/mark authz and IDOR, 2FA enable flow in profile.', paths: `${PKG}/src/Notifications/ ${PKG}/src/Preferences/ ${PKG}/src/Profile/` },
  { key: 'mcp', label: 'MCP server', security: true, focus: 'src/Mcp: Tools (doc list/read/search), Transport (AuthenticatedStreamableHttpTransport reflection swap, bearer check actually enforced at runtime), HealthServer. Path traversal in doc_read slug, bearer comparison timing, info disclosure.', paths: `${PKG}/src/Mcp/` },
  { key: 'core-registry-manager-provider', label: 'Core: registry/manager/provider/support', security: true, focus: 'ResourceRegistry, MartisManager, MartisServiceProvider, root src/*.php, src/Support, src/Concerns, src/Discovery, src/Models, src/Events, src/Exceptions. Registration integrity, discovery auto-loading safety, model guarding.', paths: `${PKG}/src/*.php ${PKG}/src/Support/ ${PKG}/src/Concerns/ ${PKG}/src/Discovery/ ${PKG}/src/Models/ ${PKG}/src/Events/ ${PKG}/src/Exceptions/ ${PKG}/src/ResourceRegistry.php ${PKG}/src/MartisManager.php` },
  { key: 'enums-contracts', label: 'Enums + Contracts', security: false, focus: 'src/Enums (40 files) + src/Contracts (19). Enum completeness/correctness (missing cases, wrong backing values), contract/interface docblock vs implementations, inheritdoc rule adherence.', paths: `${PKG}/src/Enums/ ${PKG}/src/Contracts/` },
  { key: 'fe-fields', label: 'Frontend: field renderers', security: false, focus: 'resources/js/components/fields/*. Payload-shape match with each PHP field toArray/serialize, null/empty crashes (.map/.toFixed on undefined), the FieldRenderer/FieldSwitch routing, BelongsTo async fetch unwrap, i18n.', paths: `${PKG}/resources/js/components/fields/` },
  { key: 'fe-components', label: 'Frontend: core components', security: false, focus: 'resources/js/components/* (excluding fields and metrics). Sidebar, Topbar, Layout, modals, GlobalSearch, NotificationBell, tables, drawers. Null guards, key props on lists, tooltip pattern (no native title), i18n, XSS via dangerouslySetInnerHTML.', paths: `${PKG}/resources/js/components/ excluding the fields/ and metrics/ subfolders` },
  { key: 'fe-pages-lib', label: 'Frontend: pages + lib + contexts + hooks', security: true, focus: 'resources/js/pages/*, lib/* (api client, config, i18n, componentRegistry, extensionLoader), contexts/* (Auth, Preferences, Toast), hooks/*. API envelope unwrap correctness, auth token handling, the runtime extension loader, error states, XSS.', paths: `${PKG}/resources/js/pages/ ${PKG}/resources/js/lib/ ${PKG}/resources/js/contexts/ ${PKG}/resources/js/hooks/` },
  { key: 'sec-authz-sweep', label: 'SECURITY LENS: authorization + mass-assignment', security: true, focus: 'Grep the WHOLE package for authorization gaps: every controller action and every Action/Field that mutates data — does it gate? Look for IDOR (ids from request used without ownership/authz check), mass-assignment (Model::create/update/fill with request()->all(), missing $fillable/$guarded), policy bypass, requirePlan fail-open. Cross-cut, do not limit to one dir.', paths: `grep across ${PKG}/src for ->all(), fill(, ::create(, ::update(, forceFill, request()->, Gate::, authorize, can(, policy` },
  { key: 'sec-injection-sweep', label: 'SECURITY LENS: injection + input + portability', security: true, focus: 'Grep the WHOLE package for: DB::raw / raw SQL / whereRaw / orderByRaw with interpolated input or driver-specific functions (DATE_FORMAT, GROUP_CONCAT), unbounded/unvalidated query params (sort/direction/per_page/filters), path traversal in file ops (basename, ../, storage paths), unserialize, eval, dynamic class instantiation from request input.', paths: `grep across ${PKG}/src for DB::raw, whereRaw, orderByRaw, DATE_FORMAT, unserialize, file_get_contents, ->query(, new \$, request()->query` },
  { key: 'sec-secrets-sweep', label: 'SECURITY LENS: secrets + disclosure + outbound', security: true, focus: 'Grep the WHOLE package + config + stubs for: hardcoded secrets/keys/tokens, logging of sensitive data, error responses leaking internals (stack/SQL/paths), outbound HTTP without timeout/TLS-verify (SSRF), 2FA secret + SSO token storage, MCP bearer token comparison (timing-safe?).', paths: `grep across ${PKG}/src ${PKG}/config ${PKG}/stubs for Http::, Guzzle, curl_, token, secret, password, ->info(, ->debug(, getenv, env(, hash_equals` },
  { key: 'docs-sync-package-vs-code', label: 'DOCS: package docs vs code', security: false, focus: 'Read ${PKG}/docs/*.md. For each documented artisan command, config key (config/martis.php), env var, public field/method/builder API, contract, hook — verify it EXISTS and behaves as documented in the code. Flag documented-but-missing, renamed, or behavior-drifted items, and significant public behaviour that is undocumented.', paths: `${PKG}/docs/ cross-referenced against ${PKG}/src and ${PKG}/config/martis.php` },
  { key: 'docs-sync-pkg-vs-mdx', label: 'DOCS: package docs vs martis-docs mirror', security: false, focus: 'Compare ${PKG}/docs/*.md against ${DOCS}/src/content/**/*.mdx. They must be equal in substance and reflect the source of truth. Flag: sections present in one but missing in the other, version/number drift (test counts, field counts, parity), stale commands/flags, contradictory statements. Also flag any Nova references or internal task IDs leaking into PUBLIC docs (forbidden except knowledge/PARITY_MAP.md).', paths: `${PKG}/docs/ vs ${DOCS}/src/content/` },
]

phase('Find')
log(`Auditing ${TARGETS.length} targets across martis-package + martis-docs (find -> adversarial verify -> synthesize)`)

const finderPrompt = (t) => `${SHARED}

## Your scope: ${t.label}

Focus: ${t.focus}

Where to look: ${t.paths}

Read everything in scope thoroughly. Return your confirmed findings (highest-confidence first). For each, give a precise file:line and quote the offending code in 'evidence'. Set 'subsystem' to "${t.key}". In 'coverageNote' state exactly which files you read and whether you had to truncate.`

const verifyPrompt = (f, t) => `${SHARED}

## Adversarially verify ONE finding from subsystem "${t.key}"

Title: ${f.title}
File: ${f.file}:${f.line}
Category: ${f.category} | Claimed severity: ${f.severity} | Reporter confidence: ${f.confidence}
Evidence given: ${f.evidence}
Suggested fix: ${f.suggestedFix}

Your job is to REFUTE this finding. Open ${f.file} and read the cited lines AND enough surrounding context (callers, guards, the actual method/property definitions on the receiver) to decide. Default to isReal=false unless the code genuinely confirms the defect. Common false positives to reject: a guard exists elsewhere; the value is developer-controlled not user-controlled; the framework handles it; the "missing" method/key actually exists under a different name; the convention is followed via a shared helper. If real, set adjustedSeverity to your independent assessment (may differ from the claim); if not real, set adjustedSeverity="invalid". Put your evidence in 'reasoning'.`

const reviewed = await pipeline(
  TARGETS,
  (t) => agent(finderPrompt(t), {
    label: `find:${t.key}`,
    phase: 'Find',
    schema: FINDINGS_SCHEMA,
    agentType: 'code-analyzer',
    model: 'sonnet',
    effort: t.security ? 'high' : 'medium',
  }),
  (found, t) => {
    const list = (found && found.findings) ? found.findings : []
    if (list.length === 0) return []
    return parallel(list.map((f) => () =>
      agent(verifyPrompt(f, t), {
        label: `verify:${t.key}`,
        phase: 'Verify',
        schema: VERDICT_SCHEMA,
        agentType: 'code-analyzer',
        model: 'sonnet',
      }).then((v) => ({ ...f, subsystem: t.key, verdict: v })).catch(() => null)
    ))
  },
)

const confirmed = reviewed.flat().filter(Boolean).filter((f) => f.verdict && f.verdict.isReal)
log(`Verified ${confirmed.length} real findings out of ${reviewed.flat().filter(Boolean).length} raw`)

phase('Synthesize')
const bySeverity = (s) => confirmed.filter((f) => (f.verdict.adjustedSeverity || f.severity) === s).length
const totals = {
  confirmed: confirmed.length,
  critical: bySeverity('critical'),
  high: bySeverity('high'),
  medium: bySeverity('medium'),
  low: bySeverity('low'),
}

const synth = await agent(
  `You are writing the consolidated audit report for the martis/martis ecosystem sweep.

Below is the JSON array of ADVERSARIALLY-CONFIRMED findings (false positives already removed). Each has: title, file, line, severity, category, evidence, suggestedFix, confidence, subsystem, and a verdict {isReal, reasoning, adjustedSeverity, fixNote}.

Use adjustedSeverity (the verifier's independent call) as the authoritative severity.

Write TWO files:

1. ${OUT}/AUDIT-REPORT.md — a human-readable report:
   - Executive summary (totals by severity + category, the headline risks).
   - Findings grouped by severity (Critical, High, Medium, Low), each: title, file:line, category, subsystem, the evidence quote, the (verifier-corrected) suggested fix, and confidence.
   - A "Doc drift" section consolidating all doc-sync findings (package docs vs code vs martis-docs).
   - A closing "Fix sequencing" note: which are safe one-liners vs which are larger/entangled (e.g. cross-driver SQL portability).

2. ${OUT}/findings.json — the raw confirmed findings array as JSON (machine-readable, for the fix phase).

Create the ${OUT} directory if needed (use your Bash tool: mkdir -p ${OUT}). Use the Write tool for both files.

Return a short JSON object: { reportPath, findingsPath, totals } where totals echoes the counts.

CONFIRMED FINDINGS JSON:
${JSON.stringify(confirmed)}`,
  { label: 'synthesize', phase: 'Synthesize', model: 'opus', effort: 'high' },
)

return { totals, synth }
