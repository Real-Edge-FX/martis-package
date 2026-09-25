<?php

return [
    // A relationship write names a record its picker does not offer (the
    // relatable query, the related resource's policies). Nova's wording.
    'relatable' => 'Este :attribute não pode ser associado a este recurso.',
    // An attach names a record the attach picker does not offer.
    'relatable_attachment' => 'Este registro não pode ser vinculado a este recurso.',
    // A Tag sync removes a record the detach{Model} policy keeps attached.
    'detachable' => 'Este :attribute não pode desvincular um dos seus registros.',
];
