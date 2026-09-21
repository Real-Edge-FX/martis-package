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
 * the list or map. A string that does not decode to an array is left as
 * is and fails validation instead of being stored.
 */
trait DecodesStructuredValues
{
    /**
     * @param  list<FieldContract>  $fields
     */
    protected function decodeStructuredValues(Request $request, array $fields): void
    {
        $decoded = [];

        foreach ($fields as $field) {
            if (! $field instanceof Field || ! $field->hasStructuredValue()) {
                continue;
            }

            $attribute = $field->attribute();
            $value = $request->input($attribute);
            if (! is_string($value)) {
                continue;
            }

            $json = json_decode($value, true);
            if (is_array($json)) {
                $decoded[$attribute] = $json;
            }
        }

        if ($decoded !== []) {
            $request->merge($decoded);
        }
    }
}
