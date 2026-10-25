<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * The filtered sales_order select every sales report starts from (aliased "o", no columns).
 * Money columns should be multiplied by self::RATE so multi-website stores sum in one currency.
 */
class OrderSelect
{
    public const RATE = 'IFNULL(o.base_to_global_rate, 1)';
    public const UTC_HOUR = "DATE_FORMAT(o.created_at, '%Y-%m-%d %H')";

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection('sales');
    }

    public function getTable(string $name): string
    {
        return $this->resource->getTableName($name, 'sales');
    }

    public function create(Filter $filter): Select
    {
        [$from, $to] = $filter->getUtcRange();
        $select = $this->getConnection()->select()
            ->from(['o' => $this->getTable('sales_order')], [])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at < ?', $to);
        if ($filter->storeIds) {
            $select->where('o.store_id IN (?)', $filter->storeIds);
        }
        if ($filter->states) {
            $select->where('o.state IN (?)', $filter->states);
        }

        return $select;
    }

    /** Orders by ID window, every state and store: the scheduled export's "new since last run". */
    public function createIdRange(int $afterId, int $upToId): Select
    {
        return $this->getConnection()->select()
            ->from(['o' => $this->getTable('sales_order')], [])
            ->where('o.entity_id > ?', $afterId)
            ->where('o.entity_id <= ?', $upToId);
    }

    /** Highest order ID, optionally only among orders created before a UTC datetime. 0 if none. */
    public function getMaxOrderId(?string $createdBeforeUtc = null): int
    {
        $select = $this->getConnection()->select()
            ->from($this->getTable('sales_order'), [new \Zend_Db_Expr('MAX(entity_id)')]);
        if ($createdBeforeUtc !== null) {
            $select->where('created_at < ?', $createdBeforeUtc);
        }

        return (int)$this->getConnection()->fetchOne($select);
    }
}
