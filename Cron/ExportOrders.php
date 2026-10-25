<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Cron;

use Tonyapps\AdvancedReports\Model\ScheduledOrderExport;

class ExportOrders
{
    public function __construct(private readonly ScheduledOrderExport $export)
    {
    }

    public function execute(): void
    {
        $this->export->execute();
    }
}
