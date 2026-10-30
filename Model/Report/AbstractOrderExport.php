<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model\Report;

use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\ReportInterface;
use Tonyapps\AdvancedReports\Model\Result;

abstract class AbstractOrderExport implements ReportInterface
{
    public function __construct(
        protected readonly OrderSelect $orderSelect,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function isPeriodic(): bool
    {
        return false;
    }

    public function run(Filter $filter): Result
    {
        $select = $this->orderSelect->create($filter);
        if ($filter->limit !== null) {
            $select->limit($filter->limit);
        }

        return $this->fromSelect($select);
    }

    abstract public function fromSelect(Select $orders): Result;

    protected function storeTime(string $utcDate): string
    {
        return (new \DateTimeImmutable($utcDate, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($this->timezone->getConfigTimezone()))
            ->format('Y-m-d H:i:s');
    }
}
