<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model\Report;

use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\ReportInterface;
use Tonyapps\AdvancedReports\Model\Result;

/**
 * Orders, revenue, AOV, refunds, discounts and (when products have cost) gross margin per period,
 * with totals compared against the previous period of the same length.
 */
class SalesOverview implements ReportInterface
{
    /** Order-level sums; derived metrics are computed from these. */
    private const SUMS = ['orders', 'revenue', 'refunded', 'discount', 'items', 'item_revenue', 'cost'];

    public function __construct(private readonly OrderSelect $orderSelect)
    {
    }

    public function getTitle(): string
    {
        return (string)__('Sales Overview');
    }

    public function isPeriodic(): bool
    {
        return true;
    }

    public function run(Filter $filter): Result
    {
        $buckets = $this->aggregate($filter);
        $previous = $this->aggregate($filter->getPrevious());

        $rows = [];
        foreach ($buckets as $period => $sums) {
            $rows[] = ['period' => $period] + $this->derive($sums);
        }
        $totals = ['period' => (string)__('Total')] + $this->derive($this->sum($buckets));
        $previousTotals = $this->derive($this->sum($previous));

        $deltas = [];
        foreach ($previousTotals as $key => $old) {
            $new = $totals[$key];
            $deltas[$key] = $new === null || $old === null || (float)$old === 0.0
                ? null
                : round(($new - $old) / abs($old) * 100, 1);
        }

        $columns = [
            'period' => ['label' => (string)__('Period'), 'type' => Result::TYPE_TEXT],
            'orders' => ['label' => (string)__('Orders'), 'type' => Result::TYPE_INT],
            'revenue' => ['label' => (string)__('Revenue'), 'type' => Result::TYPE_CURRENCY],
            'refunded' => ['label' => (string)__('Refunded'), 'type' => Result::TYPE_CURRENCY],
            'discount' => ['label' => (string)__('Discounts'), 'type' => Result::TYPE_CURRENCY],
            'aov' => ['label' => (string)__('Avg. Order Value'), 'type' => Result::TYPE_CURRENCY],
            'items_per_order' => ['label' => (string)__('Items / Order'), 'type' => Result::TYPE_DECIMAL],
        ];
        if ($totals['margin'] !== null) {
            $columns['margin'] = ['label' => (string)__('Gross Margin'), 'type' => Result::TYPE_CURRENCY];
            $columns['margin_pct'] = ['label' => (string)__('Margin %'), 'type' => Result::TYPE_PERCENT];
        }
        $keep = array_flip(array_keys($columns));
        $rows = array_map(static fn(array $row) => array_intersect_key($row, $keep), $rows);

        $previousRevenue = array_column(array_map([$this, 'derive'], array_values($previous)), 'revenue');

        return new Result(
            $columns,
            $rows,
            array_intersect_key($totals, $keep),
            array_intersect_key($deltas, $keep),
            [
                'type' => 'line',
                'data' => [
                    'labels' => array_keys($buckets),
                    'datasets' => [
                        ['label' => (string)__('Revenue'), 'data' => array_column($rows, 'revenue')],
                        // Aligned by position, so "day 3" is compared with the previous range's day 3.
                        ['label' => (string)__('Previous period'), 'data' => $previousRevenue, 'borderDash' => [5, 5]],
                    ],
                ],
            ]
        );
    }

    /**
     * @return array<string, array<string, float>> period label => raw sums, one entry per period in range
     */
    private function aggregate(Filter $filter): array
    {
        $connection = $this->orderSelect->getConnection();
        $rate = OrderSelect::RATE;
        $zero = array_fill_keys(self::SUMS, 0.0);
        $buckets = array_fill_keys($filter->getBuckets(), $zero);

        $orders = $this->orderSelect->create($filter)
            ->columns([
                'hour' => new \Zend_Db_Expr(OrderSelect::UTC_HOUR),
                'orders' => new \Zend_Db_Expr('COUNT(*)'),
                'revenue' => new \Zend_Db_Expr("SUM(o.base_grand_total * $rate)"),
                'refunded' => new \Zend_Db_Expr("SUM(IFNULL(o.base_total_refunded, 0) * $rate)"),
                'discount' => new \Zend_Db_Expr("SUM(ABS(IFNULL(o.base_discount_amount, 0)) * $rate)"),
                'items' => new \Zend_Db_Expr('SUM(o.total_qty_ordered)'),
            ])
            ->group('hour');

        // Cost is read from leaf items (as core's invoice cost total does) and revenue from the
        // item that carries the price: the configurable parent, or the item itself.
        // ponytail: fixed-price bundles repeat the parent's revenue for each costed child; split by
        // child cost share if bundles with cost data show up.
        $items = $this->orderSelect->create($filter)
            ->join(['i' => $this->orderSelect->getTable('sales_order_item')], 'i.order_id = o.entity_id', [])
            ->joinLeft(['p' => $this->orderSelect->getTable('sales_order_item')], 'p.item_id = i.parent_item_id', [])
            ->where('i.product_type NOT IN (?)', ['configurable', 'bundle'])
            ->where('i.base_cost > 0')
            ->columns([
                'hour' => new \Zend_Db_Expr(OrderSelect::UTC_HOUR),
                'item_revenue' => new \Zend_Db_Expr(
                    'SUM(IF(i.base_row_total > 0 OR p.item_id IS NULL,'
                    . ' i.base_row_total - IFNULL(i.base_discount_amount, 0),'
                    . " p.base_row_total - IFNULL(p.base_discount_amount, 0)) * $rate)"
                ),
                'cost' => new \Zend_Db_Expr("SUM(i.base_cost * i.qty_ordered * $rate)"),
            ])
            ->group('hour');

        foreach ([$orders, $items] as $select) {
            foreach ($connection->fetchAll($select) as $row) {
                $bucket = $filter->getBucket($row['hour']);
                unset($row['hour']);
                foreach ($row as $key => $value) {
                    $buckets[$bucket][$key] += (float)$value;
                }
            }
        }

        return $buckets;
    }

    /**
     * @param array<string, array<string, float>> $buckets
     * @return array<string, float>
     */
    private function sum(array $buckets): array
    {
        $total = array_fill_keys(self::SUMS, 0.0);
        foreach ($buckets as $sums) {
            foreach ($sums as $key => $value) {
                $total[$key] += $value;
            }
        }

        return $total;
    }

    /**
     * @param array<string, float> $s
     * @return array<string, float|int|null>
     */
    private function derive(array $s): array
    {
        $hasCost = $s['cost'] > 0;

        return [
            'orders' => (int)$s['orders'],
            'revenue' => round($s['revenue'], 2),
            'refunded' => round($s['refunded'], 2),
            'discount' => round($s['discount'], 2),
            'aov' => $s['orders'] > 0 ? round($s['revenue'] / $s['orders'], 2) : 0.0,
            'items_per_order' => $s['orders'] > 0 ? round($s['items'] / $s['orders'], 2) : 0.0,
            'margin' => $hasCost ? round($s['item_revenue'] - $s['cost'], 2) : null,
            'margin_pct' => $hasCost && $s['item_revenue'] > 0
                ? round(($s['item_revenue'] - $s['cost']) / $s['item_revenue'] * 100, 1)
                : null,
        ];
    }
}
