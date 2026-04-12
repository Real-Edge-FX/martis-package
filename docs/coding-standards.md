# Coding Standards

This document defines the mandatory coding standards for the `packages/martis/` codebase.

---

## 1. PHP DocBlocks

### Directive 1 — Required DocBlock Format

Every **public** method (and protected methods when relevant) MUST have a DocBlock.

#### Format

```php
/**
 * Summary in imperative form, lowercase except proper nouns.
 *
 * Optional longer description paragraph.
 *
 * @param  Type   $paramName  Description of the parameter.
 * @param  Type   $paramName  Another parameter description.
 *
 * @return ReturnType  Description of what is returned, or null when X.
 */
```

#### Rules

- **Summary line**: imperative mood, starts lowercase (except proper nouns), no trailing period
- **`@param`**: align type, `$name`, and description in columns using spaces
- **`@return`**: always include type + description (omit the entire `@return` tag only when the return type is `void`)
- **`@param` section**: omit entirely if the method has no parameters
- **Language**: English only — no Portuguese in DocBlocks
- **Inline DocBlocks**: single-line `/** Summary. */` format is allowed for simple methods (constructors injecting dependencies, trivial one-liners)

#### Examples

```php
/**
 * Return the fields that belong to this resource.
 *
 * @param  Request  $request  The incoming HTTP request.
 *
 * @return list<FieldContract>  The field instances to render.
 */
public function fields(Request $request): array

/**
 * Build a query for the resource index listing.
 *
 * Override to add tenant scoping, ownership filtering, or any structural
 * query constraint applied server-side before pagination.
 *
 * @param  Builder<Model>  $query
 * @return Builder<Model>
 */
public static function indexQuery(Request $request, Builder $query): Builder

/** Create the controller and inject the resource registry. */
public function __construct(private readonly ResourceRegistry $registry) {}
```

---

### Directive 2 — Contracts vs Implementations

#### Contracts / Interfaces

Every method in a contract interface MUST have a **full DocBlock** including summary, `@param`, and `@return` (when applicable).

```php
interface ResourceContract
{
    /**
     * Return the fields that belong to this resource.
     *
     * @param  Request  $request  The incoming HTTP request.
     *
     * @return list<FieldContract>  The field instances to render.
     */
    public function fields(Request $request): array;
}
```

#### Implementing Classes

Classes that implement a contract MUST use `/** {@inheritDoc} */` on every method that comes directly from the contract. This avoids DocBlock duplication while preserving IDE/tooling support.

```php
class PostResource extends Resource
{
    /** {@inheritDoc} */
    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
        ];
    }
}
```

Methods added to an implementing class that are **not** declared in any contract MUST have a full DocBlock.

---

### Contract-to-Implementation Map

| Contract | Implementing Base |
|---|---|
| `ResourceContract` | `Resource`, `ProfileResource` |
| `FieldContract` | `Field` and all field classes |
| `ActionContract` | `Action`, `DestructiveAction` |
| `LayoutContract` | `Panel`, `Tab`, `TabGroup` |
| `OverrideContract` | `Override`, `DrawerOverride` |
| `PaginationContract` | Internal use |

---

## 2. Enums Over Strings

Every parameter accepting a finite set of values MUST use a PHP 8.1+ backed enum. Strings are only acceptable when the domain is genuinely open (user input, file paths).

- Enums live in `Martis\Enums\`
- Use `enum Foo: string` (string-backed)
- Contracts MUST type-hint the enum, not `string`

See the `Enums/` directory for the full catalog.

---

## 3. General PHP Style

- PHP 8.2+ features are welcome (readonly properties, enums, intersection types)
- Constructor property promotion for dependency injection
- No unused `use` imports
- `declare(strict_types=1)` at the top of every file
