<?php

declare(strict_types=1);

use Martis\Fields\GuardSelect;

// GuardSelect needs the container (GuardCatalog reads config/auth.php), so
// this lives in Feature rather than next to the Select unit tests.
it('GuardSelect inherits the Select defaults: no option search box, no custom values, no remote search', function () {
    $field = GuardSelect::make('guard_name');

    expect($field->hasSearchableOptions())->toBeFalse()
        ->and($field->allowsCustomValues())->toBeFalse()
        ->and($field->hasRemoteOptionsSearch())->toBeFalse();
});
