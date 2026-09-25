<?php

// One process runs the whole suite, and it needs more than the 128M of a
// stock php.ini. CI's PHP (setup-php) runs with no limit, and one set on the
// command line (-d memory_limit=…) can be higher still: raise a lower limit
// to 1G, keep a higher one or none.
$limit = (string) ini_get('memory_limit');

if ($limit !== '-1' && ini_parse_quantity($limit) < 1024 ** 3) {
    ini_set('memory_limit', '1G');
}

require __DIR__.'/../vendor/autoload.php';
