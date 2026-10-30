<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model\Report;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\Report\OrderItemsExport;

class OrderExportTest extends TestCase
{
    public function testStreamFetchesRowByRowInsteadOfFetchAll(): void
    {
        $select = $this->createMock(Select::class);
        foreach (['join', 'columns', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
        $statement->expects(self::exactly(3))->method('fetch')->willReturnOnConsecutiveCalls(
            $this->item('1'),
            $this->item('2'),
            false
        );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('fetchAll');
        $connection->method('query')->willReturn($statement);
        $orderSelect = $this->createMock(OrderSelect::class);
        $orderSelect->method('create')->willReturn($select);
        $orderSelect->method('getConnection')->willReturn($connection);
        $orderSelect->method('getTable')->willReturnArgument(0);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');

        $tz = new \DateTimeZone('UTC');
        $stream = (new OrderItemsExport($orderSelect, $timezone))
            ->stream(new Filter(new \DateTimeImmutable('2026-09-01', $tz), new \DateTimeImmutable('2026-09-30', $tz)));

        self::assertInstanceOf(\Generator::class, $stream);
        $rows = iterator_to_array($stream, false);
        self::assertSame(['1', '2'], array_column($rows, 'item_id'));
        self::assertSame(12.35, $rows[0]['base_price']);
    }

    private function item(string $id): array
    {
        return [
            'item_id' => $id, 'created_at' => '2026-09-02 10:00:00', 'qty_ordered' => '1', 'qty_invoiced' => '0',
            'qty_shipped' => '0', 'qty_refunded' => '0', 'qty_canceled' => '0', 'base_price' => '12.345',
            'base_row_total' => '1', 'base_discount_amount' => '0', 'base_tax_amount' => '0',
            'base_row_total_incl_tax' => '1',
        ];
    }
}
