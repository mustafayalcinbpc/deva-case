<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\Procedure;
use Illuminate\Support\Facades\DB;

/**
 * `currentPublishedVersion` ilişkisi currentVersion() ile aynı versiyonu verir (K-15) ve
 * listelerde prosedür başına ayrı sorgu atmadan eager load edilir.
 */
class CurrentVersionRelationTest extends ProcedureTestCase
{
    public function test_relation_matches_current_version_through_time(): void
    {
        $live = $this->procedure('PRC-A');
        $this->publishVersion($live, [['steps' => 1]]);
        $this->at('09:00:00');
        $this->publishVersion($live, [['steps' => 1]]);
        $this->publishVersion($live, [['steps' => 1]], publishedAt: now()->addDay());
        $this->draft($live);

        $scheduledOnly = $this->procedure('PRC-B');
        $this->publishVersion($scheduledOnly, [['steps' => 1]], publishedAt: now()->addHours(2));

        $draftOnly = $this->procedure('PRC-C');
        $this->draft($draftOnly);

        $this->assertMatchesCurrentVersion(['PRC-A' => 2, 'PRC-B' => null, 'PRC-C' => null]);

        $this->at('11:00:00');
        $this->assertMatchesCurrentVersion(['PRC-A' => 2, 'PRC-B' => 1, 'PRC-C' => null]);

        $this->at('09:00:00', '2026-10-10');
        $this->assertMatchesCurrentVersion(['PRC-A' => 3, 'PRC-B' => 1, 'PRC-C' => null]);
    }

    public function test_relation_is_eager_loaded_with_a_constant_number_of_queries(): void
    {
        foreach (['PRC-A', 'PRC-B', 'PRC-C'] as $code) {
            $procedure = $this->procedure($code);
            $this->publishVersion($procedure, [['steps' => 1]]);
            $this->publishVersion($procedure, [['steps' => 1]]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $procedures = Procedure::with('currentPublishedVersion')->get();
        $procedures->each(fn (Procedure $procedure) => $procedure->currentPublishedVersion);

        $this->assertSame(2, $queries);
        $this->assertSame([2, 2, 2], $procedures->map(fn (Procedure $procedure) => $procedure->currentPublishedVersion->version)->all());
    }

    /**
     * @param  array<string, ?int>  $expected  kod => versiyon numarası
     */
    private function assertMatchesCurrentVersion(array $expected): void
    {
        $procedures = Procedure::with('currentPublishedVersion')->orderBy('code')->get();

        foreach ($procedures as $procedure) {
            $current = $procedure->currentVersion();
            $this->assertSame($current?->id, $procedure->currentPublishedVersion?->id, "{$procedure->code}: eager load");
            $this->assertSame($current?->id, $procedure->currentPublishedVersion()->first()?->id, "{$procedure->code}: ilişki sorgusu");
            $this->assertSame($expected[$procedure->code], $current?->version, $procedure->code);
        }
    }
}
