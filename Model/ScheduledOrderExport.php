<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime;
use Psr\Log\LoggerInterface;
use Tonyapps\AdvancedReports\Model\Report\OrderExport;
use Tonyapps\AdvancedReports\Model\Report\OrderItemsExport;

/**
 * Emails orders placed since the last run as CSV. The mark (last exported order ID) only moves after
 * the email is sent, and each run is capped at the max ID seen at start, so every order lands in
 * exactly one email even when a run fails or cron skips.
 */
class ScheduledOrderExport
{
    public const FLAG = 'tonyapps_advancedreports_order_export_last_id';
    public const FILE_ORDERS = 'orders';
    public const FILE_ITEMS = 'items';

    private const XML_PATH_ENABLED = 'tonyapps_advancedreports/order_export/enabled';
    private const XML_PATH_RECIPIENTS = 'tonyapps_advancedreports/order_export/recipients';
    private const XML_PATH_FILES = 'tonyapps_advancedreports/order_export/files';
    private const TEMPLATE = 'tonyapps_advancedreports_order_export';
    private const FIRST_RUN_HOURS = 24;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly FlagManager $flagManager,
        private readonly OrderSelect $orderSelect,
        private readonly OrderExport $orderExport,
        private readonly OrderItemsExport $orderItemsExport,
        private readonly CsvWriter $csvWriter,
        private readonly AttachmentMailer $mailer,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return int orders exported (0 when disabled, nothing new, or misconfigured) */
    public function execute(): int
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED)) {
            return 0;
        }
        $recipients = $this->getRecipients();
        if (!$recipients) {
            $this->logger->warning('Advanced Reports order export is enabled but has no valid recipients.');
            return 0;
        }

        $lastId = $this->flagManager->getFlagData(self::FLAG);
        if ($lastId === null) {
            $lastId = $this->orderSelect->getMaxOrderId(
                gmdate(DateTime::DATETIME_PHP_FORMAT, time() - self::FIRST_RUN_HOURS * 3600)
            );
        }
        $lastId = (int)$lastId;
        $upToId = $this->orderSelect->getMaxOrderId();
        if ($upToId <= $lastId) {
            return 0;
        }

        $orders = $this->orderExport->fromSelect($this->orderSelect->createIdRange($lastId, $upToId));
        if (!$orders->rows) {
            $this->flagManager->saveFlag(self::FLAG, $upToId);
            return 0;
        }

        $files = $this->getFiles();
        $stamp = gmdate('Ymd_His');
        $attachments = [];
        if (in_array(self::FILE_ORDERS, $files, true)) {
            $attachments['orders_' . $stamp . '.csv'] = $this->csvWriter->write($orders);
        }
        if (in_array(self::FILE_ITEMS, $files, true)) {
            $attachments['order_items_' . $stamp . '.csv'] = $this->csvWriter->write(
                $this->orderItemsExport->fromSelect($this->orderSelect->createIdRange($lastId, $upToId))
            );
        }

        // Throws on failure, which leaves the mark where it was so the next run retries the same orders.
        $this->mailer->send($recipients, self::TEMPLATE, [
            'count' => count($orders->rows),
            'first' => $orders->rows[0]['increment_id'],
            'last' => $orders->rows[count($orders->rows) - 1]['increment_id'],
        ], $attachments);
        $this->flagManager->saveFlag(self::FLAG, $upToId);

        return count($orders->rows);
    }

    /** @return string[] */
    private function getRecipients(): array
    {
        $emails = array_map('trim', explode(',', (string)$this->scopeConfig->getValue(self::XML_PATH_RECIPIENTS)));

        return array_values(array_filter($emails, static fn (string $e) => (bool)filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /** @return string[] */
    private function getFiles(): array
    {
        $files = array_filter(explode(',', (string)$this->scopeConfig->getValue(self::XML_PATH_FILES)));

        return $files ?: [self::FILE_ORDERS, self::FILE_ITEMS];
    }
}
