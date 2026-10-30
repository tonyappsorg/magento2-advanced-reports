<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tonyapps\AdvancedReports\Model\AttachmentMailer;
use Tonyapps\AdvancedReports\Model\CsvWriter;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\Report\OrderExport;
use Tonyapps\AdvancedReports\Model\Report\OrderItemsExport;
use Tonyapps\AdvancedReports\Model\Result;
use Tonyapps\AdvancedReports\Model\ScheduledOrderExport;

class ScheduledOrderExportTest extends TestCase
{
    private array $config;
    private FlagManager&MockObject $flags;
    private OrderSelect&MockObject $orderSelect;
    private AttachmentMailer&MockObject $mailer;
    private ScheduledOrderExport $export;
    private ?int $cap = null;

    protected function setUp(): void
    {
        $this->config = [
            'tonyapps_advancedreports/order_export/enabled' => '1',
            'tonyapps_advancedreports/order_export/recipients' => 'erp@example.com, not-an-email ,acct@example.com',
            'tonyapps_advancedreports/order_export/files' => 'orders,items',
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $path) => !empty($this->config[$path]));

        $this->flags = $this->createMock(FlagManager::class);
        $this->orderSelect = $this->createMock(OrderSelect::class);
        $this->orderSelect->method('createIdRange')->willReturn($this->createMock(Select::class));
        $this->orderSelect->method('capOrderId')->willReturnCallback(fn (int $after, int $upTo) => $this->cap ?? $upTo);
        $this->mailer = $this->createMock(AttachmentMailer::class);

        $result = new Result(
            ['increment_id' => ['label' => 'Order #', 'type' => Result::TYPE_TEXT]],
            [['increment_id' => '000000041'], ['increment_id' => '000000042']]
        );
        $orders = $this->createMock(OrderExport::class);
        $orders->method('fromSelect')->willReturn($result);
        $items = $this->createMock(OrderItemsExport::class);
        $items->method('fromSelect')->willReturn($result);

        $this->export = new ScheduledOrderExport(
            $scopeConfig, $this->flags, $this->orderSelect, $orders, $items, new CsvWriter(), $this->mailer, new NullLogger()
        );
    }

    public function testDisabledDoesNothing(): void
    {
        $this->config['tonyapps_advancedreports/order_export/enabled'] = '0';
        $this->mailer->expects($this->never())->method('send');

        $this->assertSame(0, $this->export->execute());
    }

    public function testNoValidRecipientsDoesNothing(): void
    {
        $this->config['tonyapps_advancedreports/order_export/recipients'] = 'nope, also-nope';
        $this->mailer->expects($this->never())->method('send');

        $this->assertSame(0, $this->export->execute());
    }

    public function testNothingNewSendsNothingAndKeepsMark(): void
    {
        $this->flags->method('getFlagData')->willReturn(42);
        $this->orderSelect->method('getMaxOrderId')->willReturn(42);
        $this->mailer->expects($this->never())->method('send');
        $this->flags->expects($this->never())->method('saveFlag');

        $this->assertSame(0, $this->export->execute());
    }

    public function testSendsNewOrdersThenAdvancesMarkToMaxSeenAtStart(): void
    {
        $this->flags->method('getFlagData')->willReturn(40);
        $this->orderSelect->method('getMaxOrderId')->willReturn(42);
        $this->orderSelect->expects($this->exactly(2))->method('createIdRange')->with(40, 42);
        $this->mailer->expects($this->once())->method('send')->with(
            ['erp@example.com', 'acct@example.com'],
            'tonyapps_advancedreports_order_export',
            ['count' => 2, 'first' => '000000041', 'last' => '000000042'],
            $this->callback(fn (array $files) => count($files) === 2
                && str_contains(implode('', array_keys($files)), 'order_items_')
                && str_contains(reset($files), '000000041'))
        );
        $this->flags->expects($this->once())->method('saveFlag')->with(ScheduledOrderExport::FLAG, 42);

        $this->assertSame(2, $this->export->execute());
    }

    public function testFailedSendLeavesMarkSoNextRunRetries(): void
    {
        $this->flags->method('getFlagData')->willReturn(40);
        $this->orderSelect->method('getMaxOrderId')->willReturn(42);
        $this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP down'));
        $this->flags->expects($this->never())->method('saveFlag');

        $this->expectException(\RuntimeException::class);
        $this->export->execute();
    }

    public function testFirstRunStartsFromLast24Hours(): void
    {
        $this->flags->method('getFlagData')->willReturn(null);
        // 24h-ago cutoff (first-run start) vs the settle cutoff a few minutes ago (end of the window).
        $this->orderSelect->method('getMaxOrderId')->willReturnCallback(
            fn (?string $before = null) => strtotime($before . ' UTC') < time() - 3600 ? 39 : 42
        );
        $this->orderSelect->expects($this->atLeastOnce())->method('createIdRange')->with(39, 42);
        $this->flags->expects($this->once())->method('saveFlag')->with(ScheduledOrderExport::FLAG, 42);

        $this->export->execute();
    }

    public function testOnlyOrdersFileWhenItemsNotSelected(): void
    {
        $this->config['tonyapps_advancedreports/order_export/files'] = 'orders';
        $this->flags->method('getFlagData')->willReturn(40);
        $this->orderSelect->method('getMaxOrderId')->willReturn(42);
        $this->mailer->expects($this->once())->method('send')->with(
            $this->anything(), $this->anything(), $this->anything(),
            $this->callback(fn (array $files) => count($files) === 1 && str_starts_with((string)array_key_first($files), 'orders_'))
        );

        $this->export->execute();
    }

    public function testOnlyExportsOrdersOlderThanTheSettleWindow(): void
    {
        $this->flags->method('getFlagData')->willReturn(40);
        $this->orderSelect->expects($this->once())->method('getMaxOrderId')
            ->with($this->callback(function (?string $before) {
                $age = time() - strtotime($before . ' UTC');
                return $age >= 9 * 60 && $age <= 11 * 60;
            }))
            ->willReturn(42);

        $this->export->execute();
    }

    public function testEmailDisabledKeepsMarkSoOrdersAreNotLost(): void
    {
        $this->config['system/smtp/disable'] = '1';
        $this->flags->method('getFlagData')->willReturn(40);
        $this->orderSelect->method('getMaxOrderId')->willReturn(42);
        $this->mailer->expects($this->never())->method('send');
        $this->flags->expects($this->never())->method('saveFlag');

        $this->assertSame(0, $this->export->execute());
    }

    public function testBacklogIsSentInCappedBatchesAndMarkMovesToTheCap(): void
    {
        $this->cap = 1040;
        $this->flags->method('getFlagData')->willReturn(40);
        $this->orderSelect->method('getMaxOrderId')->willReturn(5000);
        $this->orderSelect->expects($this->once())->method('capOrderId')
            ->with(40, 5000, ScheduledOrderExport::MAX_ORDERS_PER_RUN);
        $this->orderSelect->expects($this->exactly(2))->method('createIdRange')->with(40, 1040);
        $this->flags->expects($this->once())->method('saveFlag')->with(ScheduledOrderExport::FLAG, 1040);

        $this->export->execute();
    }
}
