<?php

declare(strict_types=1);

namespace Martis\Testing;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Pest / PHPUnit trait that proves a request does not run a query per row.
 *
 * `assertFlatQueries()` seeds a few rows, runs the request and counts its
 * queries, seeds many more, runs it again and asserts the count did not
 * grow. A listing that queries per row (an N+1) fails, and the failure names
 * the queries that repeated.
 *
 *     uses(\Martis\Testing\AssertsFlatQueries::class);
 *
 *     it('lists customers in a flat number of queries', function () {
 *         $this->actingAs(User::factory()->create());
 *
 *         $this->assertFlatQueries(
 *             seed: fn (int $rows) => Customer::factory()->count($rows)->create(),
 *             request: fn () => $this->getJson('/martis/api/customers')->assertOk(),
 *         );
 *     });
 *
 * The `seed` closure receives the number of rows to ADD: `few` on the first
 * call, then `many - few`, so the table holds `many` rows for the second run.
 * Create whatever each row needs (related records, flags) inside it.
 */
trait AssertsFlatQueries
{
    /**
     * Assert `$request` runs the same number of queries with `$few` rows as
     * with `$many`.
     *
     * @param  Closure(int): mixed  $seed  Adds the given number of rows.
     * @param  Closure(): mixed  $request  Runs the request under test.
     * @param  bool  $warmUp  Run `$request` once, uncounted, before the first
     *                        count: for a request whose first run fills a cache.
     * @return array{few: int, many: int} The query counts of the two runs.
     */
    public function assertFlatQueries(Closure $seed, Closure $request, int $few = 2, int $many = 20, bool $warmUp = false): array
    {
        Assert::assertGreaterThan($few, $many, 'assertFlatQueries() needs more rows in the second run than in the first.');

        $seed($few);
        if ($warmUp) {
            $request();
        }
        $fewQueries = $this->collectQueries($request);

        $seed($many - $few);
        $manyQueries = $this->collectQueries($request);

        Assert::assertSame(
            count($fewQueries),
            count($manyQueries),
            $this->describeQueryGrowth($fewQueries, $manyQueries, $few, $many),
        );

        return ['few' => count($fewQueries), 'many' => count($manyQueries)];
    }

    /**
     * The SQL of every query `$request` runs, on any connection.
     *
     * @param  Closure(): mixed  $request
     * @return list<string>
     */
    private function collectQueries(Closure $request): array
    {
        $queries = [];
        $collecting = true;

        DB::listen(static function (QueryExecuted $query) use (&$queries, &$collecting): void {
            if ($collecting) {
                $queries[] = $query->sql;
            }
        });

        try {
            $request();
        } finally {
            // The listener cannot be removed: it goes inert instead.
            $collecting = false;
        }

        return $queries;
    }

    /**
     * @param  list<string>  $few
     * @param  list<string>  $many
     */
    private function describeQueryGrowth(array $few, array $many, int $fewRows, int $manyRows): string
    {
        $seen = array_count_values($few);
        $extra = [];

        foreach (array_count_values($many) as $sql => $times) {
            $more = $times - ($seen[$sql] ?? 0);
            if ($more > 0) {
                $extra[$sql] = $more;
            }
        }

        arsort($extra);

        $lines = [];
        foreach ($extra as $sql => $more) {
            $lines[] = sprintf('  +%d x %s', $more, $sql);
        }

        return sprintf(
            'Expected a flat number of queries, but the request ran %d with %d rows and %d with %d rows.%s',
            count($few),
            $fewRows,
            count($many),
            $manyRows,
            $lines === [] ? '' : "\nQueries that ran more often with more rows:\n".implode("\n", $lines),
        );
    }
}
