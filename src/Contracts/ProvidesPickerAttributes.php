<?php

declare(strict_types=1);

namespace Martis\Contracts;

/**
 * A relation field whose picker reads attributes of the related record
 * besides its key and title: `BelongsTo`, `MorphTo` and `Tag`.
 *
 * The relatable endpoint serialises each picker row as `id`, `_title` and
 * these attributes only (the display value of the related resource's index
 * field of that name, when the user may see it), so the picker never
 * receives the related resource's other columns.
 */
interface ProvidesPickerAttributes
{
    /**
     * The attributes of the related model the picker shows: its title
     * attribute, and its subtitle attribute when it shows subtitles.
     *
     * @return list<string>
     */
    public function pickerAttributes(): array;
}
