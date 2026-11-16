<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\Exception\LocalizedException;
use Tonyapps\AdvancedReports\Model\Report\OrderExport;

/**
 * Real-data preview for Tonyapps_EmailDesigner (registered in etc/di.xml, duck-typed): the order export email for
 * the orders the next run would send, or for the last 24 hours, built by ScheduledOrderExport::nextRange() and
 * emailVariables(). Read-only: the mark is not moved, no CSV is written, nothing is sent.
 */
class EmailPreview
{
    private const SINCE_LAST_EXPORT = 'since_last_export';
    private const LAST_24_HOURS = 'last_24_hours';

    public function __construct(
        private readonly ScheduledOrderExport $export,
        private readonly OrderSelect $orderSelect,
        private readonly OrderExport $orderExport
    ) {
    }

    /** @return array<string, string> only the windows that have orders (a few primary-key lookups) */
    public function getRecords(string $templateId, int $limit): array
    {
        $records = [];
        if ($this->export->nextRange() !== null) {
            $records[self::SINCE_LAST_EXPORT] = (string)__('Orders since last export');
        }
        if ($this->export->nextRange(true) !== null) {
            $records[self::LAST_24_HOURS] = (string)__('Orders of the last 24 hours');
        }
        return array_slice($records, 0, $limit, true);
    }

    /** None: the export email goes out from the default store view, as AttachmentMailer sends it. */
    public function getStoreId(string $templateId, string $recordId): ?int
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function getVariables(string $templateId, string $recordId): array
    {
        $range = $this->export->nextRange($recordId === self::LAST_24_HOURS);
        $orders = $range ? $this->orderExport->fromSelect($this->orderSelect->createIdRange(...$range)) : null;
        if (!$orders?->rows) {
            throw new LocalizedException(__('There are no orders in this window, so no export email would be sent.'));
        }
        return $this->export->emailVariables($orders);
    }
}
