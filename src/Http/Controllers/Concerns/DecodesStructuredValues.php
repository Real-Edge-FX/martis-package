<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\Field;

/**
 * Restore the structure of field values a multipart request carried as
 * JSON strings.
 *
 * A form that uploads a file is sent as `multipart/form-data`, and FormData
 * carries strings only, so the SPA JSON-encodes every list or map value on
 * that path (`buildFormData()` in `lib/api.ts`). Each resource controller
 * runs this before validating and filling so the fields whose value is a
 * structure (`Field::hasStructuredValue()`: Repeater, MultiSelect,
 * BooleanGroup, KeyValue, Tag, MorphTo, Sparkline) see the same shape the
 * JSON request path sends: rules such as `array` pass, `fill()` receives
 * the list or map.
 *
 * Any other value that is not a list or a map is left as is and, when the
 * field rejects unstructured values (`Field::rejectsUnstructuredValue()`),
 * its attribute is returned, so the caller validates it with `array` on top
 * of the field's own rules: a string that does not decode to an array on
 * either path, and a number or a boolean a JSON request sends. The request
 * fails with a 422 whether or not the field declares an `array` rule, and
 * `fill()` never receives the value (a Repeater would empty its rows, a
 * KeyValue clear its map, a MultiSelect its list, a Tag detach every tag).
 * A MorphTo, a readonly or computed field and a field with a `fillUsing()`
 * callback are left to their fill. Null and the empty string, which the
 * multipart path sends for null, still clear the field.
 *
 * A `BelongsTo` submitted as an `{ id, title }` map (a create opened from a
 * relationship panel pre-fills the parent that way) is reduced to its id
 * here, after the decoding, so a consumer rule such as `Rule::exists()` and
 * the fill receive a scalar (v2.10.0). A map whose id is not an int or a
 * string is left as it is for the Relatable rule to refuse.
 */
trait DecodesStructuredValues
{
    /**
     * @param  list<FieldContract>  $fields
     * @return list<string> Attributes of fields that reject unstructured
     *                      values whose value is neither a list or map nor
     *                      a string that decodes to one.
     */
    protected function decodeStructuredValues(Request $request, array $fields): array
    {
        $decoded = [];
        $undecodable = [];

        foreach ($fields as $field) {
            if (! $field instanceof Field || ! $field->hasStructuredValue()) {
                continue;
            }

            $attribute = $field->attribute();
            $value = $request->input($attribute);
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $json = is_string($value) ? json_decode($value, true) : null;
            if (is_array($json)) {
                $decoded[$attribute] = $json;
            } elseif ($field->rejectsUnstructuredValue()) {
                $undecodable[] = $attribute;
            }
        }

        if ($decoded !== []) {
            $request->merge($decoded);
        }

        // A BelongsTo the form holds as its `{ id, title }` map reaches the
        // rules and the fill as the id (see `BelongsTo::reduceSubmittedValue()`).
        // After the decoding above, so a map sent as JSON text is reduced too.
        $reduced = BelongsTo::reduceSubmittedValues($fields, $request->all());
        if ($reduced !== []) {
            $request->merge($reduced);
        }

        return $undecodable;
    }
}
