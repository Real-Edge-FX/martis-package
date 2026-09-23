<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
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
 * A non-empty string that does not decode to an array is left as is and
 * its attribute is returned, so the caller validates it with `array` on
 * top of the field's own rules: the request fails with a 422 whether or
 * not the field declares an `array` rule, and `fill()` never receives the
 * string (a Repeater would empty its rows, a KeyValue clear its map, a
 * Tag detach every tag). The empty string, which the multipart path sends
 * for null, still clears the field.
 */
trait DecodesStructuredValues
{
    /**
     * @param  list<FieldContract>  $fields
     * @return list<string> Attributes of structured fields whose value is a
     *                      string that does not decode to a list or map.
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
            if (! is_string($value) || $value === '') {
                continue;
            }

            $json = json_decode($value, true);
            if (is_array($json)) {
                $decoded[$attribute] = $json;
            } else {
                $undecodable[] = $attribute;
            }
        }

        if ($decoded !== []) {
            $request->merge($decoded);
        }

        return $undecodable;
    }
}
