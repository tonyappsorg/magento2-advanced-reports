<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tonyapps\AdvancedReports\Model\AttachmentMailer;
use Tonyapps\AdvancedReports\Model\CsvWriter;
use Tonyapps\AdvancedReports\Model\EmailPreview;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\Report\OrderExport;
use Tonyapps\AdvancedReports\Model\Report\OrderItemsExport;
use Tonyapps\AdvancedReports\Model\Result;
use Tonyapps\AdvancedReports\Model\ScheduledOrderExport;

class EmailPreviewTest extends TestCase
{
    private const TEMPLATE = 'tonyapps_advancedreports_order_export_email_template';

    private FlagManager&MockObject $flags;
    private OrderSelect&MockObject $orderSelect;
    private OrderExport&MockObject $orders;
    private AttachmentMailer&MockObject $mailer;
    private ScheduledOrderExport $export;

    protected function setUp(): void
    {
        $config = [
            'tonyapps_advancedreports/order_export/enabled' => '1',
            'tonyapps_advancedreports/order_export/recipients' => 'erp@example.com',
            'tonyapps_advancedreports/order_export/files' => 'orders',
            'tonyapps_advancedreports/order_export/email_template' => self::TEMPLATE,
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $config[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $path) => !empty($config[$path]));
        $this->flags = $this->createMock(FlagManager::class);
        $this->orderSelect = $this->createMock(OrderSelect::class);
        $this->orderSelect->method('createIdRange')->willReturn($this->createMock(Select::class));
        $this->orderSelect->method('capOrderId')->willReturnArgument(1);
        // 24h-ago cutoff => 30, settle cutoff => 42.
        $this->orderSelect->method('getMaxOrderId')->willReturnCallback(
            fn (?string $before = null) => strtotime($before . ' UTC') < time() - 3600 ? 30 : 42
        );
        $this->orders = $this->createMock(OrderExport::class);
        $this->orders->method('fromSelect')->willReturn(new Result(
            ['increment_id' => ['label' => 'Order #', 'type' => Result::TYPE_TEXT]],
            [['increment_id' => '000000041'], ['increment_id' => '000000042']]
        ));
        $this->mailer = $this->createMock(AttachmentMailer::class);
        $this->export = new ScheduledOrderExport(
            $scopeConfig, $this->flags, $this->orderSelect, $this->orders,
            $this->createMock(OrderItemsExport::class), new CsvWriter(), $this->mailer, new NullLogger()
        );
    }

    public function testVariablesAreTheOnesTheExportSendsAndTheMarkStays(): void
    {
        $this->flags->method('getFlagData')->willReturn(40);
        // Only the export's own run moves the mark; the preview after it does not.
        $this->flags->expects(self::once())->method('saveFlag');
        $sent = null;
        $this->mailer->expects(self::once())->method('send')->willReturnCallback(
            function (array $to, string $template, array $vars) use (&$sent) {
                $sent = $vars;
            }
        );
        $this->export->execute();

        $this->orderSelect->expects(self::atLeastOnce())->method('createIdRange')->with(40, 42);
        $preview = new EmailPreview($this->export, $this->orderSelect, $this->orders);

        self::assertSame(['count' => 2, 'first' => '000000041', 'last' => '000000042'], $sent);
        self::assertSame($sent, $preview->getVariables(self::TEMPLATE, 'since_last_export'));
    }

    public function testRecordsOfferOnlyWindowsWithOrders(): void
    {
        // Mark at 42: nothing new since the last export, but the last 24 hours have orders.
        $this->flags->method('getFlagData')->willReturn(42);
        $this->flags->expects(self::never())->method('saveFlag');
        $this->mailer->expects(self::never())->method('send');
        $preview = new EmailPreview($this->export, $this->orderSelect, $this->orders);

        self::assertSame(['last_24_hours' => 'Orders of the last 24 hours'], $preview->getRecords(self::TEMPLATE, 20));

        $this->orderSelect->expects(self::once())->method('createIdRange')->with(30, 42);
        self::assertSame(2, $preview->getVariables(self::TEMPLATE, 'last_24_hours')['count']);
    }

    public function testNoRecordStoreSoTheDefaultStoreViewIsUsed(): void
    {
        $this->flags->expects(self::never())->method('saveFlag');

        self::assertNull((new EmailPreview($this->export, $this->orderSelect, $this->orders))->getStoreId(self::TEMPLATE, 'since_last_export'));
    }

    public function testEmptyWindowIsRefused(): void
    {
        $this->flags->method('getFlagData')->willReturn(42);

        $this->expectException(LocalizedException::class);
        (new EmailPreview($this->export, $this->orderSelect, $this->orders))->getVariables(self::TEMPLATE, 'since_last_export');
    }
}
