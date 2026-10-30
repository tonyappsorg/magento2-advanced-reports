<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Model\OrderSelect;

class OrderSelectTest extends TestCase
{
    public function testCapIsTheNthOrderAfterTheMarkOrTheWindowEnd(): void
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'order'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->expects(self::exactly(2))->method('limit')->with(1, 999)->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls('1040', false);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $orderSelect = new OrderSelect($resource);

        self::assertSame(1040, $orderSelect->capOrderId(40, 5000, 1000));
        self::assertSame(500, $orderSelect->capOrderId(40, 500, 1000));
    }
}
