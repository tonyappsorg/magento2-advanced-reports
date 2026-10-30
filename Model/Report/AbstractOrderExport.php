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
        return $this->fromSelect($this->filtered($filter));
    }

    /** @param Select $orders a sales_order select aliased "o" with no columns (OrderSelect::create / createIdRange) */
    public function fromSelect(Select $orders): Result
    {
        return new Result($this->getColumns(), iterator_to_array($this->iterate($orders), false));
    }

    /**
     * Rows one at a time, for the admin CSV export: a range of up to 1100 days doesn't fit in memory as one array.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function stream(Filter $filter): \Generator
    {
        return $this->iterate($this->filtered($filter));
    }

    /** @return array<string, array{label: string, type: string}> */
    abstract public function getColumns(): array;

    /** Adds this export's joins, columns and order to the bare sales_order select. */
    abstract protected function select(Select $orders): Select;

    /** @param array<string, mixed> $row */
    abstract protected function row(array $row): array;

    private function filtered(Filter $filter): Select
    {
        $select = $this->orderSelect->create($filter);
        if ($filter->limit !== null) {
            $select->limit($filter->limit);
        }

        return $select;
    }

    private function iterate(Select $orders): \Generator
    {
        $statement = $this->orderSelect->getConnection()->query($this->select($orders));
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            yield $this->row($row);
        }
    }

    protected function storeTime(string $utcDate): string
    {
        return (new \DateTimeImmutable($utcDate, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone($this->timezone->getConfigTimezone()))
            ->format('Y-m-d H:i:s');
    }
}
