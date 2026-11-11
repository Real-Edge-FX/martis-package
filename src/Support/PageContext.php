<?php

declare(strict_types=1);

namespace Martis\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Facts about the rows of one listed page, kept for the life of the request.
 *
 * A listing endpoint (a resource index, a lens, a relationship panel) opens a
 * context, hands it to `Resource::prefetchIndexPage()` after reading the page
 * and before serializing its rows, and the code that runs while the rows are
 * serialized (a field resolver, `canSee()`, an action's `canRun()`) reads the
 * facts back with `get()`, falling back to its own domain logic for a row the
 * context does not hold.
 *
 * The context lives in the request's attributes, so it is per request (also
 * under Octane) and never shared. Outside a listing request `current()`
 * returns an empty context that is not kept: `get()` returns its default and
 * a `put()` is lost, so a caller's fallback runs on action POSTs, detail,
 * edit and count requests.
 *
 *     // in the resource
 *     public static function prefetchIndexPage(Request $request, Collection $rows, PageContext $context): void
 *     {
 *         $context->putMany('has_grant', Grant::whereIn('user_id', $rows->modelKeys())
 *             ->pluck('user_id', 'user_id')->map(fn () => true)->all());
 *     }
 *
 *     // in a canRun(), a field or a canSee()
 *     $context = PageContext::current($request);
 *     $has = $context->has('has_grant', $model)
 *         ? $context->get('has_grant', $model)
 *         : $model->grants()->exists();
 */
final class PageContext
{
    /** The request attribute that holds the open context. */
    private const ATTRIBUTE = 'martis.page_context';

    /** @var array<string, array<string, mixed>> */
    private array $facts = [];

    /**
     * Open a fresh context for `$request`, replacing any earlier one. Called by
     * the listing endpoints, never by application code.
     */
    public static function begin(Request $request): self
    {
        $context = new self;
        $request->attributes->set(self::ATTRIBUTE, $context);

        return $context;
    }

    /**
     * The context of the listing `$request` (the current request when null) is
     * serving, or an empty one that is not kept when it serves none.
     */
    public static function current(?Request $request = null): self
    {
        $request ??= request();
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : new self;
    }

    /**
     * Whether a context is open on `$request` (the current request when null),
     * i.e. whether a listing is being served.
     */
    public static function isOpen(?Request $request = null): bool
    {
        return ($request ?? request())->attributes->get(self::ATTRIBUTE) instanceof self;
    }

    /**
     * Keep `$value` for `$row` under `$key`. `$row` is a model or its key.
     */
    public function put(string $key, Model|int|string $row, mixed $value): self
    {
        $this->facts[$key][self::rowKey($row)] = $value;

        return $this;
    }

    /**
     * Keep a value per row key under `$key`: `[row key => value]`.
     *
     * @param  array<int|string, mixed>  $values
     */
    public function putMany(string $key, array $values): self
    {
        foreach ($values as $row => $value) {
            $this->facts[$key][(string) $row] = $value;
        }

        return $this;
    }

    /**
     * The value kept for `$row` under `$key`, or `$default` when there is none.
     */
    public function get(string $key, Model|int|string $row, mixed $default = null): mixed
    {
        $rowKey = self::rowKey($row);

        if (isset($this->facts[$key]) && array_key_exists($rowKey, $this->facts[$key])) {
            return $this->facts[$key][$rowKey];
        }

        return $default;
    }

    /**
     * Whether a value (a null one included) is kept for `$row` under `$key`.
     */
    public function has(string $key, Model|int|string $row): bool
    {
        return isset($this->facts[$key]) && array_key_exists(self::rowKey($row), $this->facts[$key]);
    }

    private static function rowKey(Model|int|string $row): string
    {
        return (string) ($row instanceof Model ? $row->getKey() : $row);
    }
}
