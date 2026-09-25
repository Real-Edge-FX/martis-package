<?php

return [
    // A relationship write names a record its picker does not offer (the
    // relatable query, the related resource's policies). Nova's wording.
    'relatable' => 'This :attribute may not be associated with this resource.',
    // An attach names a record the attach picker does not offer.
    'relatable_attachment' => 'This record may not be attached to this resource.',
    // A Tag sync removes a record the detach{Model} policy keeps attached.
    'detachable' => 'This :attribute may not detach one of its records.',
];
