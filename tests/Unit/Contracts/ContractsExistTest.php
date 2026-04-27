<?php

use Martis\Contracts\CardContract;
use Martis\Contracts\DashboardContract;
use Martis\Contracts\FieldContract;
use Martis\Contracts\FilterContract;
use Martis\Contracts\LensContract;
use Martis\Contracts\PaginationContract;
use Martis\Contracts\ResourceContract;

it('ResourceContract interface exists', function () {
    expect(interface_exists(ResourceContract::class))->toBeTrue();
});

it('FieldContract interface exists', function () {
    expect(interface_exists(FieldContract::class))->toBeTrue();
});

it('schema foundation contracts exist', function () {
    expect(interface_exists(FilterContract::class))->toBeTrue()
        ->and(interface_exists(LensContract::class))->toBeTrue()
        ->and(interface_exists(CardContract::class))->toBeTrue()
        ->and(interface_exists(DashboardContract::class))->toBeTrue();
});

it('PaginationContract interface exists', function () {
    expect(interface_exists(PaginationContract::class))->toBeTrue();
});

it('ResourceContract declares required methods', function () {
    $methods = get_class_methods(ResourceContract::class);
    expect($methods)->toContain('fields')
        ->and($methods)->toContain('model')
        ->and($methods)->toContain('newModel')
        ->and($methods)->toContain('uriKey')
        ->and($methods)->toContain('label')
        ->and($methods)->toContain('singularLabel')
        ->and($methods)->toContain('authorizedToView')
        ->and($methods)->toContain('authorizedToCreate')
        ->and($methods)->toContain('authorizedToUpdate')
        ->and($methods)->toContain('authorizedToDelete');
});

it('ResourceContract declares all context-aware field resolution methods', function () {
    $methods = get_class_methods(ResourceContract::class);
    expect($methods)->toContain('fieldsForIndex')
        ->and($methods)->toContain('fieldsForDetail')
        ->and($methods)->toContain('fieldsForCreate')
        ->and($methods)->toContain('fieldsForUpdate')
        ->and($methods)->toContain('fieldsForInlineCreate')
        ->and($methods)->toContain('fieldsForPreview')
        ->and($methods)->toContain('filters')
        ->and($methods)->toContain('lenses')
        ->and($methods)->toContain('cards')
        ->and($methods)->toContain('dashboards');
    // fieldsForForms must NOT exist — it was removed in
    expect($methods)->not->toContain('fieldsForForms');
});

it('FieldContract declares required methods', function () {
    $methods = get_class_methods(FieldContract::class);
    expect($methods)->toContain('attribute')
        ->and($methods)->toContain('label')
        ->and($methods)->toContain('type')
        ->and($methods)->toContain('resolve')
        ->and($methods)->toContain('fill')
        ->and($methods)->toContain('toArray')
        ->and($methods)->toContain('nullable')
        ->and($methods)->toContain('readonly')
        ->and($methods)->toContain('required')
        ->and($methods)->toContain('showOnIndex')
        ->and($methods)->toContain('hideFromIndex')
        ->and($methods)->toContain('showOnDetail')
        ->and($methods)->toContain('hideFromDetail')
        ->and($methods)->toContain('showOnForms')
        ->and($methods)->toContain('hideFromForms')
        ->and($methods)->toContain('isShownOnIndex')
        ->and($methods)->toContain('isShownOnDetail')
        ->and($methods)->toContain('isShownOnForms');
});

it('PaginationContract declares required methods', function () {
    $methods = get_class_methods(PaginationContract::class);
    expect($methods)->toContain('total')
        ->and($methods)->toContain('perPage')
        ->and($methods)->toContain('currentPage')
        ->and($methods)->toContain('lastPage')
        ->and($methods)->toContain('from')
        ->and($methods)->toContain('to')
        ->and($methods)->toContain('toArray');
});
