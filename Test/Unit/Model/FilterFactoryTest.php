<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;
use PHPUnit\Framework\TestCase;
use Tonyapps\AdvancedReports\Model\FilterFactory;

class FilterFactoryTest extends TestCase
{
    public function testDefaultStatesLeaveOutCanceledAndUnpaidOrders(): void
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        $orderConfig = $this->createMock(OrderConfig::class);
        $orderConfig->method('getStates')->willReturn(array_fill_keys(
            ['new', 'pending_payment', 'processing', 'complete', 'closed', 'canceled', 'holded', 'payment_review'],
            'x'
        ));
        $factory = new FilterFactory($timezone, $orderConfig);

        self::assertSame(['new', 'processing', 'complete', 'closed', 'holded'], $factory->create()->states);
        self::assertSame(['pending_payment'], $factory->create(['states' => ['pending_payment']])->states);
    }
}
