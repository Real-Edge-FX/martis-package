# Upgrading

> **These docs describe Martis v2.** Martis v1.x read `Select` and `MultiSelect` options as `[label => value]`; v2.0 and later read `[value => label]`, Nova's order. If your app still runs v1.x, flip the option arrays in the examples, or upgrade with the steps below.

The sections below list the breaking changes of each major version and what to change in an app.

## Upgrading to v2.0 from v1.x

Require the new major; a `^1.x` constraint never installs it:

```bash
composer require martis/martis:^2.0
```

Then change the app as the sections below say, and deploy it with the steps in [Deploying the upgrade](#deploying-the-upgrade).

### Choice-field options follow Nova's order (high impact)

`Select::options()` and `MultiSelect::options()` read `[value => label]`, the order Nova uses for `Select`, `MultiSelect` and `BooleanGroup`: the array key is what the field stores, the array value is what the user sees. v1.x read the array the other way round, `[label => value]`.

| Call | v1.x stored | v2.0 stores |
|---|---|---|
| `options(['Draft' => 'draft'])` | `draft` | `Draft` |
| `options(['draft' => 'Draft'])` | `Draft` | `draft` |
| `options(fn () => User::pluck('name', 'id')->all())` | the name | the id |
| `options(['draft', 'published'])` | `draft`, `published` | `0`, `1` |

Nothing fails at runtime when an array keeps the v1 order: the field stores the label instead of the value. Review every call.

**What to change:**

1. **Flip every associative array written label first.** `['Draft' => 'draft']` becomes `['draft' => 'Draft']`, and `pluck('id', 'name')` becomes `pluck('name', 'id')`.
2. **Turn lists into maps.** A list is read as Nova reads it, keyed 0, 1, 2…, so `['draft', 'published']` now stores `0` and `1`. When each value is its own label, pass `array_combine($values, $values)`; for an enum, pass the class (`options(Status::class)`) instead of a list of its values. A list of numbers shifts by one value with no error: `options([1, 2, 3])`, `range(1, 12)` or `array_column(Rating::cases(), 'value')` stored the numbers themselves on v1.x and now store 0, 1, 2, so a stored 1 shows as "2" and picking "3" stores 2.
3. **Rewrite MultiSelect groups in Nova's format.** `['Backend' => ['PHP' => 'php']]` becomes `['php' => ['label' => 'PHP', 'group' => 'Backend']]`. The old format now throws an `InvalidArgumentException` that names the field and the option.
4. **Replace `optionsFromMap()` with `options()`.** It was removed: `options()` reads the same `[value => label]` map and also takes a closure. A call left behind fails with an undefined-method error.
5. **Check `searchOptionsUsing()` closures.** They return the same shapes as `options()`, in the same order, so a list is keyed 0, 1, 2 and stores a position in that search's results, which changes with the term. Return `[value => label]`, for example `->pluck('name', 'id')`, or `array_combine($values, $values)` when each value is its own label. A closure that returns a non-empty list logs a warning, at most once per field per request.
6. **Review what else reads a flipped array.** On v1.x one array could serve both a field and a `SelectFilter` (for example a `Post::STATUSES` constant). Filters keep `[label => value]` (see below), so once the array is flipped for the field, the filter shows `draft` as the label and applies `where status = 'Draft'`: zero results, no error. Give the filter `array_flip(Post::STATUSES)`, or its own array. Review the other readers of a flipped array the same way: a `Rule::in(array_values(...))` rule now lists the labels (use `array_keys()`), and a Badge map or a `displayUsing()` lookup built from it is now keyed by value.
7. **Update generated code.** The `BulkAssignRole` action that `martis:roles` generates builds its role picker with `pluck('id', 'name')`; with v2.0 it would receive the role name: `Role::find('Admin')` answers "That role no longer exists" on MySQL and SQLite, and throws a `QueryException` on PostgreSQL, whose integer id column rejects the text. Change it to `pluck('name', 'id')` (new scaffolds already do). If you published the stubs with `martis:stubs`, update `stubs/martis/roles-bulk-assign-role-action.stub` the same way. The `AGENTS.md` / `CLAUDE.md` primers that `martis:agents` wrote under v1.x recommend `options($enum::values())`, which v2.0 reads as a list: regenerate them with `php artisan martis:agents --force` (update `stubs/martis/agents/AGENTS.md.stub` first if you published it).
8. **Subclasses.** A subclass of `Select` or `MultiSelect` that overrides `options()` must accept `iterable|Arrayable|string|\Closure`: `options()` now takes a Collection or any other iterable, as in Nova, and `MultiSelect::options()` takes an enum class too.

**Finding the calls:**

```bash
grep -rnF -e '->options(' -e 'optionsFromMap(' -e 'searchOptionsUsing(' app/ Modules/ packages/
```

Add every other folder that holds PHP source (a `src/` or `domain/` folder, local packages, a modular layout); `grep` warns about a folder that does not exist and searches the others. Every `Select` and `MultiSelect` hit needs a look. A filter's `public function options(Request $request)` keeps its order, but check the array it returns when a field shares it (step 6). A multi-line chain shows only its `options(` line, so read each hit in its file.

**Records saved with the wrong order.** A field whose array was already written value first under v1.x (for example `['admin' => 'Administrator']`) stored the label. v2.0 reads that array correctly, so those records hold a label where a value is expected: fix the stored values with a data migration.

**The stored-label warning.** Reading a record whose stored value matches the label of a static option and the value of none logs a warning, in production too, at most once per request for each model class and field, naming the model, the record, the field and the value. It fires in both cases above: an array still written label first, and a record saved while it was. For options given as a list, it also fires for a stored value that is the label of another option (the list of numbers of step 2). It reads the stored value, before `resolveUsing()`, and skips computed fields and a `Select` with `allowCustomValues()`. It names the first matching record only and never checks options that come from a closure (the `pluck()` case), so to find every affected record, query the column for values that are labels.

Both warnings can log a false positive: a field can store a valid 0-based position that also happens to read as another option's label, for example a Nova rating that stores `0..4` on purpose and shows `"1".."5"` (`[0 => '1', 1 => '2', ...]`, `array_combine(range(0, 4), range(1, 5))`, or `options([1, 2, 3, 4, 5])` ported straight from Nova). Reading a stored `1` there is correct, not stale data, but would otherwise warn on every request. Call `withoutOptionOrderWarnings()` on that field once the array is confirmed right; it silences the plain stored-label warning and the list-shift warning for that field, and the `searchOptionsUsing()` list warning, which a resolver that filters a fixed list and keeps its keys can trigger when the search term is empty.

**What does not change:**

- **Filters** keep Nova's filter order, `[label => value]`: a filter's `options(Request $request)` is unchanged, exactly as in Nova. An array a filter shares with a field is the exception: see step 6.
- **`BooleanGroup`** already read `[flag key => label]`.
- **Enum classes** (`options(Status::class)`) store the case value, as before; `MultiSelect` now accepts them too.
- **The field payload** keeps its shape (`options: [{label, value, group?}]`), so custom frontend components keep working. `group` is new on `Select`, whose dropdown now shows grouped options under their headings.

### Relationship writes need the related resource's `viewAny`

Creating, editing or deleting a record through a relationship panel (`HasMany`, `HasOne`, `MorphMany`, `MorphOne`, and their endpoints) now needs the related resource's `viewAny`, as its own per-id endpoints do since v1.34.0 and as Nova does. v1.x checked only the parent's `viewAny` / `view` and the related record's `create` / `update` / `delete`.

A user whose policy denies `viewAny` on the related resource gets a 403 on those writes, and the panel no longer offers Create, Edit, Delete, Restore or Force delete. It still lists the records, as in v1.x.

**What to change:** if that user should keep writing through the panel, grant `viewAny` on the related resource and confine what they see with `indexQuery()`. A resource that is not `routable()` keeps working as a relation target. See [Authorization → `viewAny` is the entry gate](authorization.md#viewany-is-the-entry-gate).

### Through relationships offer no Create

`HasOneThrough` and `HasManyThrough` never offer Create, as in Nova, and a create through a Through relationship answers 403. Edit and Delete now show by default, under the related resource's policies. `canCreate()` has no effect; `canCreate(true)` logs a warning naming the field. See [Relationships → Upgrading the Through fields from 1.x](relationships.md#upgrading-the-through-fields-from-1x).

### Relationship panels list what the related index lists

A `HasMany`, `HasManyThrough`, `MorphMany`, `BelongsToMany` or `MorphToMany` panel now applies the related resource's `scopes()` and `indexQuery()`, as its index does and as Nova's relationship index does. v1.x listed every record of the relationship, including the ones those hooks hide (another tenant's, archived ones).

**What to change:**

1. **Check hooks that should not apply to panels.** An `indexQuery()` meant for the index page only (an order, a default filter) now also shapes the panels; test `$request->route('relationship')` to tell a panel apart (it is not set on the counts a parent's index computes).
2. **Know what reaches a panel.** On a plain `HasMany` / `MorphMany` panel the hooks run grouped on the panel's query: an `orWhere()` stays inside the group, their order comes before the panel's `?sort=` (as on the index), and a hook that joins must select its table's columns, as the index needs (a search there, as on the index, fails when the joined table has a column of the same name). On a Through or pivot panel, and for every count, they run on a fresh query and the panel keeps the keys they return: their order and aliases do not reach the rows (a panel cannot sort by an alias only the hook adds). The key subquery costs little: a 100-row page of a parent index that counts three relationships runs 2 queries instead of the 302 of the per-row counts before v2.0, about an order of magnitude faster in one machine's run with 100,000 and with 500,000 related rows. Columns need no qualifying there.

The `BelongsToMany` and `MorphToMany` panels also apply their soft-delete filter now; before, *Only trashed* listed the active records.

The same hooks now shape what counts the related records: the relationship count a `HasMany`, `MorphMany`, `BelongsToMany` or `MorphToMany` field shows on the index (`showOnIndex()`), now computed with the page instead of per row, and the one-of-many "1 of N" count and `aggregateVia()` tile. The hooks narrow the relationship's own definition and nothing else: a global scope the relationship removes (`->withoutGlobalScope(...)`) stays removed, as in 1.x.

The page's count resolves the relationship as Laravel's `withCount()` does, on a model that holds no record, where v1.x counted on each loaded record. A relationship method that reads the parent's attributes gets `null` there: one that throws without its record is still counted per row, as in v1.x, but one that reads `null` counts the wrong rows. **Check** that the relationships you count on the index (`showOnIndex()`) do not depend on the parent's attributes.

A one-record card (`HasOne`, `HasOneOfMany`, `HasOneThrough`, `MorphOne`, `MorphOneOfMany`) now shows its record only when the user may `view` it, as Nova hides that panel; otherwise the card is not rendered at all (its endpoint answers `meta.hidden: true`) and its Edit and Delete answer 404. Grant `view` where the card should show the record. A custom card component that reads the endpoint should treat `meta.hidden` as "render nothing". Creating a second record on a `HasOne` / `MorphOne` card answers `422` with the reason as its `message` (it answered `500`); a one-of-many card now takes more records, as its many relationship does.

### Creating a record needs `viewAny`

`POST /api/resources/{resource}` and the inline create (its form and its store) answer `403` when the user may not list the resource, as its show, update and destroy endpoints do since v1.34.0. v1.x checked `create` only.

The create form's own endpoints (`sync-field` and `fields/{field}/options` in the create context) need it too, and the inline create buttons of `BelongsTo`, `MorphTo`, `Tag`, `BelongsToMany` and `MorphToMany` are offered only when the related resource allows both `create` and `viewAny`.

**What to change:** grant `viewAny` to a user who should create records, and confine what they see with `indexQuery()` if needed.

### Actions: who may run them, and on which records

An action run on records changed in v2.0:

- **`canRun()` and the policy must both allow it.** The per-row map the index and the panels send (`_actionAuthorization`) now includes the resource's `runAction` / `runDestructiveAction` policy (falling back to `update` / `delete`), as the run always did: an action the policy refuses shows disabled instead of failing when run. A `canRun()` does not replace the policy, unlike Nova 5.
- **Trashed records run.** A selected record the resource soft-deletes is looked up with the trashed ones, since the index and the panels list them.
- **A partial selection runs on what resolves.** Ids outside the resource's `scopes()` / `indexQuery()`, or that no longer exist, are left out and the action runs on the others, as in Nova.
- **404 when nothing resolves.** A run whose selected records all fail to resolve answers `404` (`One or more selected resources could not be found.`) instead of running `handle()` on nothing and answering "Done".
- **422 with an empty selection.** An action that is not `standalone()` and names no record answers `422` (`resources`), as a pivot action already did. A `standalone()` action runs on no record, whatever the request names.
- **`scopes()` apply.** A run looks records up through the resource's declarative `scopes()`, then `indexQuery()`, as the index lists them.
- **A panel's run finds what the panel lists.** A run from a `HasMany`, `HasManyThrough` or `MorphMany` panel (`viaResource`, `viaResourceId`, `viaRelationship`) and a pivot action on a `BelongsToMany` / `MorphToMany` panel resolve the records the panel lists: the relationship's rows (a global scope it removes stays removed), the related resource's `scopes()` and `indexQuery()`, and the trashed ones only when the panel offers its trashed filter (`canViewTrashed()`). A pivot action no longer runs on a row those hooks hide (it answers 404), and a `standalone()` action run with a relationship the parent does not declare answers 404.
- **A queued action handles what the run resolved.** Its job (`ExecuteAction`, `ExecutePivotAction` for a pivot action) reloads the records by key without global scopes, as Laravel restores a job's models, so it handles every record the run resolved: a trashed one, one only the panel's relationship reaches. v1.x reloaded them through the model's global scopes, which in a queue worker (no user, no tenant) could leave records out.

**What to change:** a client that posts to `/actions/{action}` without `resources` for an action that is not standalone must send the ids, or declare the action `standalone()`.

### The schema cache expires after a day

The `schema` cache layer now expires after a day by default (`MARTIS_CACHE_SCHEMA_TTL=1440`); v1.x kept it with no expiration. Every cache key also carries the installed `martis/martis` version, so an upgrade of the package rebuilds every layer on its own and leaves the previous version's entries behind.

**What to change:**

1. **Check the TTL.** An empty `MARTIS_CACHE_SCHEMA_TTL=` (the `.env` block the v1.x docs showed) and a `config/martis.php` published before v2.0 keep "no expiration". Set `MARTIS_CACHE_SCHEMA_TTL=1440`, or republish the config and merge your changes.
2. **Prune on the database and file stores.** Those stores delete an expired entry only when its key is read again, which an old version's key never is. Run `php artisan martis:cache:prune` after each upgrade, or schedule it. Redis and memcached need nothing.

See [Cache → Invalidation](cache.md#invalidation).

### Deploying the upgrade

Run these in each environment, after the code with the flipped arrays is deployed there, in this order:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear schema
php artisan martis:cache:prune
```

1. **Republish the assets.** The grouped `Select` dropdown and the fix for a `''` option ship in the frontend bundle; without the republish the browser keeps the v1 bundle.
2. **Clear the schema cache, after the deploy.** It holds the options as they were read, for up to a day by default, and with no expiration when `MARTIS_CACHE_SCHEMA_TTL` is empty or `config/martis.php` was published before v2.0 (see [The schema cache expires after a day](#the-schema-cache-expires-after-a-day)); a new package version does not help here, because the arrays you flipped are your app's code. Cleared before the new code is deployed, the next page opened caches the old options again, so clear it after the deploy, in every environment.
3. **Prune the old cache entries**, on the database and file stores (a no-op on the others).
