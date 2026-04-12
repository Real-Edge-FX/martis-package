# Coding Standards

## PHP DocBlocks

All public (and protected, when relevant) methods in `packages/martis/src/` must carry a DocBlock.

### Format

```php
/**
 * Summary in one line (imperative, lowercase except proper nouns).
 *
 * Optional longer description paragraph.
 *
 * @param  int    $userId    The user identifier.
 * @param  int    $objectId  The object identifier.
 * @param  bool   $explain   When true, the result includes an explain payload in metadata.
 *
 * @return PermissionResultDto|null  The resolved result, or null when the object does not exist.
 */
```

**Rules:**

- **Summary line**: imperative mood, lowercase first word (except proper nouns), no trailing period.
- **`@param`**: align type, `$name`, and description in columns.
- **`@return`**: include type and a brief description. Omit entirely for `void` returns.
- **Language**: English only (see [REA-1294](https://github.com/Real-Edge-FX/martis)).
- Omit `@param` section entirely when there are no parameters.
- Omit `@return` section entirely when the return type is `void`.

### Single-line DocBlocks

Acceptable for trivial accessors, simple getters, and constructors that inject dependencies:

```php
/** Return the model attribute name this field maps to (e.g. "title"). */
public function attribute(): string { ... }

/** Create a new field instance. */
protected function __construct(string $attribute, ?string $label = null) { ... }
```

---

## Contracts vs Implementations

### Contracts / Interfaces

Contracts carry the **full** DocBlock — this is the single source of truth for every method's contract:

```php
// Interface (contract)
interface ResourceContract
{
    /**
     * Return the fields that belong to this resource.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @return list<FieldContract>  List of field instances.
     */
    public function fields(Request $request): array;
}
```

### Implementation classes

Classes that implement a contract use `/** {@inheritDoc} */` — no duplication:

```php
// Implementation
class PostResource extends Resource
{
    /** {@inheritDoc} */
    public function fields(Request $request): array
    {
        // ...
    }
}
```

**Exceptions**: if the implementation has meaningful extra behaviour not described in the contract, add a note below `{@inheritDoc}`:

```php
/**
 * {@inheritDoc}
 *
 * Also fires the BeforeSave event before persisting.
 */
public function beforeSave(Model $model, Request $request, bool $creating): void { ... }
```

---

## Enforcement

Run the project's DocBlock checker to verify compliance before every PR:

```bash
php /tmp/check_docblocks.php   # zero output = fully compliant
```

CI will fail on any new public method without a DocBlock.
