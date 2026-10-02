<?php

return [
    // A relationship write names a record its picker does not offer (the
    // relatable query, the related resource's policies). Nova's wording.
    'relatable' => 'Este :attribute não pode ser associado a este recurso.',
    // An attach names a record the attach picker does not offer.
    'relatable_attachment' => 'Este registo não pode ser associado a este recurso.',
    // A Tag sync removes a record the detach{Model} policy keeps attached.
    'detachable' => 'Este :attribute não pode desassociar um dos seus registos.',
    // An image URL a field writes must be an absolute https:// URL.
    'https_url' => 'O campo :attribute tem de ser um URL válido que comece por https://.',
    // An upload a browser runs when it is opened (HTML, SVG, XML, script files); a File
    // field accepts one only when acceptedTypes() lists it or allowActiveContent() is set.
    'active_content' => 'O campo :attribute não pode ser um ficheiro HTML, SVG, XML ou de script.',
];
