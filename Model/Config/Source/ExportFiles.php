<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Tonyapps\AdvancedReports\Model\ScheduledOrderExport;

class ExportFiles implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ScheduledOrderExport::FILE_ORDERS, 'label' => __('Orders (one row per order)')],
            ['value' => ScheduledOrderExport::FILE_ITEMS, 'label' => __('Order items (one row per item)')],
        ];
    }
}
