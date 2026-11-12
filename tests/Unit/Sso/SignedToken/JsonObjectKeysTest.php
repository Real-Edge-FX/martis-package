<?php

declare(strict_types=1);

use Martis\Sso\SignedToken\JsonObjectKeys;

it('accepts objects whose keys are all different', function (string $json) {
    expect(JsonObjectKeys::hasRepeatedKey($json))->toBeFalse();
})->with([
    'empty object' => ['{}'],
    'empty array' => ['[]'],
    'flat' => ['{"a":1,"b":2,"c":3}'],
    'nested with the same key in different objects' => ['{"a":{"a":1},"b":{"a":2}}'],
    'same key in sibling objects of an array' => ['{"x":[{"a":1},{"a":2}]}'],
    'same key after a nested object closed' => ['{"a":{"b":1},"c":{"b":2}}'],
    'keys that differ by case' => ['{"sub":"1","Sub":"2"}'],
    'a key that looks like a number' => ['{"1":"a","01":"b"}'],
    'values that look like keys' => ['{"name":"\"a\":1,\"a\":2","b":"a"}'],
    'string values equal to a key name' => ['{"a":"a","b":"a"}'],
    'escaped quote and backslash in a value' => ['{"a":"x\\\\","b":"\"","c":"y"}'],
    'arrays of strings' => ['{"roles":["a","a","a"]}'],
    'deep nesting' => ['{"a":{"b":{"c":{"d":[{"e":1},{"e":2}]}}}}'],
    'whitespace' => [" {\n \"a\" : 1 ,\n \"b\" : { \"a\" : 2 }\n} "],
]);

it('finds a key that an object repeats', function (string $json) {
    expect(JsonObjectKeys::hasRepeatedKey($json))->toBeTrue();
})->with([
    'flat' => ['{"sub":"1","sub":"2"}'],
    'flat, apart' => ['{"sub":"1","email":"a","exp":"x","sub":"2"}'],
    'escaped spelling of the same key' => ['{"sub":"1","sub":"2"}'],
    'both escaped' => ['{"sub":"1","sub":"2"}'],
    'nested' => ['{"a":{"b":1,"b":2}}'],
    'deeply nested' => ['{"a":{"b":{"c":[{"d":1,"d":2}]}}}'],
    'in an object of an array' => ['[{"a":1},{"b":1,"b":2}]'],
    'after a nested value' => ['{"a":{"x":1},"b":[1,2],"a":3}'],
    'empty key twice' => ['{"":1,"":2}'],
    'a key with an escaped quote' => ['{"a\"b":1,"a\"b":2}'],
    'a key with an escaped backslash before the closing quote' => ['{"a\\\\":1,"a\\\\":2}'],
]);

it('answers true for a text it cannot follow, never "clean"', function (string $json) {
    expect(JsonObjectKeys::hasRepeatedKey($json))->toBeTrue();
})->with([
    'an unterminated string' => ['{"a'],
    'a key with a lone surrogate' => ['{"\ud800":1}'],
    'an unterminated second key' => ['{"a":1,"b'],
]);

it('is linear on a large input', function () {
    $json = '{'.implode(',', array_map(fn (int $i) => '"k'.$i.'":"v"', range(1, 5000))).'}';

    $start = microtime(true);
    $repeated = JsonObjectKeys::hasRepeatedKey($json);

    expect($repeated)->toBeFalse()->and(microtime(true) - $start)->toBeLessThan(0.5);
});
