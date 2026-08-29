<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * DECIMAL-safe count/sum over query builders. MySQL uses SQL SUM; sqlite uses bcmath
 * iteration so tests never rely on float aggregation.
 */
final class DecimalAggregator
{
    private const SCALE = 4;

    /**
     * @param  Builder<Model>  $query
     * @return array{count: int, sum: string}
     */
    public static function countAndSum(Builder $query, string $sumColumn = 'total'): array
    {
        $count = (int) (clone $query)->count();

        if ($count === 0) {
            return ['count' => 0, 'sum' => bcadd('0', '0', self::SCALE)];
        }

        $driver = $query->getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            /** @var object{aggregate_sum?: mixed}|null $row */
            $row = (clone $query)
                ->selectRaw('COALESCE(SUM('.$sumColumn.'), 0) as aggregate_sum')
                ->first();

            return [
                'count' => $count,
                'sum' => bcadd((string) ($row->aggregate_sum ?? '0'), '0', self::SCALE),
            ];
        }

        $sum = '0';
        (clone $query)
            ->select(['id', $sumColumn])
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (Model $row) use (&$sum, $sumColumn): void {
                $raw = $row->getAttribute($sumColumn);
                if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                    return;
                }

                $sum = bcadd($sum, (string) $raw, self::SCALE);
            });

        return [
            'count' => $count,
            'sum' => bcadd($sum, '0', self::SCALE),
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return array<int, array{count: int, sum: string}>
     */
    public static function countAndSumGrouped(
        Builder $query,
        string $groupColumn,
        string $sumColumn = 'total',
    ): array {
        $driver = $query->getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $rows = (clone $query)
                ->selectRaw($groupColumn.' as group_key, COUNT(*) as row_count, COALESCE(SUM('.$sumColumn.'), 0) as aggregate_sum')
                ->groupBy($groupColumn)
                ->get();

            $buckets = [];
            foreach ($rows as $row) {
                if ($row->group_key === null) {
                    continue;
                }

                $buckets[(int) $row->group_key] = [
                    'count' => (int) $row->row_count,
                    'sum' => bcadd((string) $row->aggregate_sum, '0', self::SCALE),
                ];
            }

            return $buckets;
        }

        /** @var array<int, array{count: int, sum: string}> $buckets */
        $buckets = [];

        (clone $query)
            ->select(['id', $groupColumn, $sumColumn])
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (Model $row) use (&$buckets, $groupColumn, $sumColumn): void {
                $groupKey = $row->getAttribute($groupColumn);
                if ($groupKey === null) {
                    return;
                }

                $key = (int) $groupKey;
                $bucket = $buckets[$key] ?? ['count' => 0, 'sum' => '0'];
                $bucket['count']++;

                $raw = $row->getAttribute($sumColumn);
                if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                    $bucket['sum'] = bcadd($bucket['sum'], (string) $raw, self::SCALE);
                }

                $buckets[$key] = $bucket;
            });

        return $buckets;
    }
}
