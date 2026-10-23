<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model\Report;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\ReportInterface;
use Tonyapps\AdvancedReports\Model\Result;

class SalesByPayment implements ReportInterface
{
    public function __construct(
        private readonly OrderSelect $orderSelect,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getTitle(): string
    {
        return (string)__('Sales by Payment Method');
    }

    public function isPeriodic(): bool
    {
        return false;
    }

    public function run(Filter $filter): Result
    {
        $rate = OrderSelect::RATE;
        $select = $this->orderSelect->create($filter)
            ->join(['pay' => $this->orderSelect->getTable('sales_order_payment')], 'pay.parent_id = o.entity_id', [])
            ->columns([
                'method' => 'pay.method',
                'orders' => new \Zend_Db_Expr('COUNT(*)'),
                'revenue' => new \Zend_Db_Expr("SUM(o.base_grand_total * $rate)"),
            ])
            ->group('pay.method')
            ->order('revenue DESC');
        $data = $this->orderSelect->getConnection()->fetchAll($select);

        $totalOrders = (int)array_sum(array_column($data, 'orders'));
        $totalRevenue = (float)array_sum(array_column($data, 'revenue'));

        $rows = [];
        foreach ($data as $row) {
            $orders = (int)$row['orders'];
            $revenue = (float)$row['revenue'];
            $rows[] = [
                'method' => $this->getMethodTitle((string)$row['method']),
                'orders' => $orders,
                'revenue' => round($revenue, 2),
                'aov' => round($revenue / $orders, 2),
                'share' => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 1) : 0.0,
            ];
        }

        return new Result(
            [
                'method' => ['label' => (string)__('Payment Method'), 'type' => Result::TYPE_TEXT],
                'orders' => ['label' => (string)__('Orders'), 'type' => Result::TYPE_INT],
                'revenue' => ['label' => (string)__('Revenue'), 'type' => Result::TYPE_CURRENCY],
                'aov' => ['label' => (string)__('Avg. Order Value'), 'type' => Result::TYPE_CURRENCY],
                'share' => ['label' => (string)__('Revenue Share'), 'type' => Result::TYPE_PERCENT],
            ],
            $rows,
            [
                'method' => (string)__('Total'),
                'orders' => $totalOrders,
                'revenue' => round($totalRevenue, 2),
                'aov' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0.0,
                'share' => $rows ? 100.0 : 0.0,
            ],
            [],
            [
                'type' => 'bar',
                'data' => [
                    'labels' => array_column($rows, 'method'),
                    'datasets' => [['label' => (string)__('Revenue'), 'data' => array_column($rows, 'revenue')]],
                ],
                'options' => ['indexAxis' => 'y'],
            ]
        );
    }

    private function getMethodTitle(string $code): string
    {
        $title = $this->scopeConfig->getValue('payment/' . $code . '/title');

        return $title ? $title . ' (' . $code . ')' : $code;
    }
}
