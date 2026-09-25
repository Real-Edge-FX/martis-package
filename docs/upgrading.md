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

Both warnings can log a false positive: a field can store a valid 0-based position that also happens to read as another option's label, for example a Nova rating that stores `0..4` on purpose and shows `"1".."5"` (`[0 => '1', 1 => '2', ...]`, `array_combine(range(0, 4), range(1, 5))`, or `options([1, 2, 3, 4, 5])` ported straight from Nova). Reading a stored `1` there is correct, not stale data, but would otherwise warn on every request. Call `withoutOptionOrderWarnings()` on that field once the array is confirmed right; it silences both the plain stored-label warning and the list-shift warning for that field.

**What does not change:**

- **Filters** keep Nova's filter order, `[label => value]`: a filter's `options(Request $request)` is unchanged, exactly as in Nova. An array a filter shares with a field is the exception: see step 6.
- **`BooleanGroup`** already read `[flag key => label]`.
- **Enum classes** (`options(Status::class)`) store the case value, as before; `MultiSelect` now accepts them too.
- **The field payload** keeps its shape (`options: [{label, value, group?}]`), so custom frontend components keep working. `group` is new on `Select`, whose dropdown now shows grouped options under their headings.

### Deploying the upgrade

Run these in each environment, after the code with the flipped arrays is deployed there, in this order:

```bash
php artisan martis:publish-assets
php artisan martis:cache:clear schema
```

1. **Republish the assets.** The grouped `Select` dropdown and the fix for a `''` option ship in the frontend bundle; without the republish the browser keeps the v1 bundle.
2. **Clear the schema cache, last.** It never expires by default, and it holds the options as they were read. Cleared before the new code is deployed, the next page opened caches the old options again, so clear it after the deploy, in every environment.
