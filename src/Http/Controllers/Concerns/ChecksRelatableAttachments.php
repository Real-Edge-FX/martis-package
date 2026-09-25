<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse as IlluminateJsonResponse;
use Illuminate\Http\Request;
use Martis\Fields\BelongsToMany;
use Martis\Fields\MorphToMany;
use Martis\Http\Resources\JsonErrorResponse;
use Martis\RelationshipQueryResolver;
use Martis\Resource;
use Martis\Support\IndexScope;

/**
 * The records a `BelongsToMany` / `MorphToMany` panel may attach: the ones
 * its attach picker lists.
 *
 * Nova validates every attach and every attachment update with its
 * `Laravel\Nova\Rules\RelatableAttachment` rule, on the field's attachable
 * query (`buildAttachableQuery()`). The picker and the writes share
 * `attachableQuery()` here, so an attach, a batch attach or a pivot update
 * that names a record the picker would not list answers 422 on the id
 * (`martis::validation.relatable_attachment`) instead of writing it.
 *
 * The query is the one the picker runs, without its search, order and
 * "already attached" filter: the target's `relatableQuery()`, the parent
 * resource's `relatable{PluralModelName}()` and the field's
 * `relatableQueryUsing()` closure, which receives the parent form draft
 * the request sends in `?form[attribute]=value` when it takes a third
 * argument (an empty array when the request sends none). Soft-deleted
 * records are left out, as the picker never lists them.
 */
trait ChecksRelatableAttachments
{
    /**
     * @param  array<string, mixed>  $ctx  The panel's context (`resolveContext()`)
     * @return Builder<Model>
     */
    protected function attachableQuery(Request $request, array $ctx): Builder
    {
        /** @var class-string<\Martis\Resource> $parentResourceClass */
        $parentResourceClass = $ctx['parentResourceClass'];
        /** @var class-string<\Martis\Resource> $relatedResourceClass */
        $relatedResourceClass = $ctx['relatedResourceClass'];
        /** @var BelongsToMany|MorphToMany $field */
        $field = $ctx['field'];

        /** @var Builder<Model> $query */
        $query = $relatedResourceClass::newModel()->newQuery();

        // Resource-level fences first, through the same resolver the
        // BelongsTo picker uses: the target's relatableQuery() always
        // applies, the source's relatable{PluralModelName}() narrows on top.
        // The field closure below then narrows an already-fenced query
        // instead of being the only fence on this picker.
        $query = RelationshipQueryResolver::resolve($parentResourceClass, $relatedResourceClass, $request, $query, $field);

        // The field's relatableQueryUsing() closure. A closure that takes a
        // third argument receives the parent form draft (v1.8.2): the
        // unsaved values of the parent form the frontend sends in
        // `?form[attribute]=value`, so the picker can filter on what the user
        // just picked (only the permissions of the role's chosen
        // `guard_name`). Closures of arity 2 keep working.
        $relatableClosure = $field->getRelatableQueryClosure();
        if ($relatableClosure !== null) {
            $arity = (new \ReflectionFunction($relatableClosure))->getNumberOfParameters();
            $formDraft = $arity >= 3 ? $this->collectFormDraft($request) : [];
            // Grouped: an `orWhere()` in it cannot OR the resource-level
            // fences above away (see IndexScope).
            IndexScope::grouped($query, fn (Builder $grouped) => $arity >= 3
                ? $relatableClosure($request, $grouped, $formDraft)
                : $relatableClosure($request, $grouped));
        }

        return $query;
    }

    /**
     * The 422 response when one of `$ids` names a record the attach picker
     * does not list, `null` when every one is attachable. `$errorKey` is the
     * input the ids came from (`related_id`, `related_ids`).
     *
     * @param  array<string, mixed>  $ctx  The panel's context (`resolveContext()`)
     * @param  list<int|string>  $ids
     */
    protected function notRelatableAttachment(Request $request, array $ctx, array $ids, string $errorKey): ?IlluminateJsonResponse
    {
        $wanted = [];
        foreach ($ids as $id) {
            $wanted[(string) $id] = $id;
        }

        if ($wanted === []) {
            return null;
        }

        $query = $this->attachableQuery($request, $ctx);
        $query->getQuery()->reorder();

        $found = $query
            ->whereKey(array_values($wanted))
            ->get()
            ->map(fn (Model $model): string => (string) $model->getKey())
            ->all();

        if (array_diff(array_keys($wanted), $found) === []) {
            return null;
        }

        return JsonErrorResponse::validation(
            [$errorKey => [__('martis::validation.relatable_attachment')]],
            'Validation failed.',
        )->toResponse();
    }

    /**
     * Read the parent form draft the frontend sends in `?form[*]`.
     *
     * The picker, and the attach it leads to, send the UNSAVED values of the
     * parent form (e.g. `guard_name` for a Role being created) so 3-arg
     * `relatableQueryUsing` closures can filter on them. Returns an
     * empty array when no `form` param is present; closures of arity 2
     * never see it.
     *
     * @return array<string, scalar|null>
     */
    protected function collectFormDraft(Request $request): array
    {
        $raw = $request->query('form', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            // Only scalars and null: nested arrays are not part of the
            // contract, the caller sends simple top-level form fields.
            if ($value === null || is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
