<?php

return [
    // A relationship write names a record its picker does not offer (the
    // relatable query, the related resource's policies). Nova's wording.
    'relatable' => 'This :attribute may not be associated with this resource.',
    // An attach names a record the attach picker does not offer.
    'relatable_attachment' => 'This record may not be attached to this resource.',
    // A Tag sync removes a record the detach{Model} policy keeps attached.
    'detachable' => 'This :attribute may not detach one of its records.',
    // An image URL a field writes must be an absolute https:// URL.
    'https_url' => 'The :attribute must be a valid URL that starts with https://.',
    // An upload a browser runs when it is opened (HTML, SVG, XML, script files); a File
    // field accepts one only when acceptedTypes() lists it or allowActiveContent() is set.
    'active_content' => 'The :attribute must not be an HTML, SVG, XML or script file.',
];
