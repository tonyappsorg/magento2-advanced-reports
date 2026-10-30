<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model\Report;

use Magento\Framework\DB\Select;
use Tonyapps\AdvancedReports\Model\Result;

/**
 * One row per priced order item (parent_item_id IS NULL): a configurable's parent row already carries
 * the chosen simple's SKU and the price. Base-currency row values, no totals row.
 */
class OrderItemsExport extends AbstractOrderExport
{
    private const QTY = ['qty_ordered', 'qty_invoiced', 'qty_shipped', 'qty_refunded', 'qty_canceled'];
    private const MONEY = ['base_price', 'base_row_total', 'base_discount_amount', 'base_tax_amount', 'base_row_total_incl_tax'];

    public function getTitle(): string
    {
        return (string)__('Order Items Export');
    }

    /** @param Select $orders a sales_order select aliased "o" with no columns (OrderSelect::create / createIdRange) */
    public function fromSelect(Select $orders): Result
    {
        $select = $orders
            ->join(
                ['i' => $this->orderSelect->getTable('sales_order_item')],
                'i.order_id = o.entity_id AND i.parent_item_id IS NULL',
                [
                    'item_id' => 'i.item_id', 'sku' => 'i.sku', 'name' => 'i.name', 'product_type' => 'i.product_type',
                    'qty_ordered' => 'i.qty_ordered', 'qty_invoiced' => 'i.qty_invoiced', 'qty_shipped' => 'i.qty_shipped',
                    'qty_refunded' => 'i.qty_refunded', 'qty_canceled' => 'i.qty_canceled',
                    'base_price' => 'i.base_price', 'base_row_total' => 'i.base_row_total',
                    'base_discount_amount' => 'i.base_discount_amount', 'base_tax_amount' => 'i.base_tax_amount',
                    'base_row_total_incl_tax' => 'i.base_row_total_incl_tax',
                ]
            )
            ->columns([
                'increment_id' => 'o.increment_id', 'created_at' => 'o.created_at', 'status' => 'o.status',
                'customer_email' => 'o.customer_email', 'base_currency_code' => 'o.base_currency_code',
            ])
            ->order(['o.entity_id ASC', 'i.item_id ASC']);

        $rows = [];
        foreach ($this->orderSelect->getConnection()->fetchAll($select) as $row) {
            $row['created_at'] = $this->storeTime((string)$row['created_at']);
            foreach (self::QTY as $key) {
                $row[$key] = (float)$row[$key];
            }
            foreach (self::MONEY as $key) {
                $row[$key] = round((float)$row[$key], 2);
            }
            $rows[] = $row;
        }

        $text = Result::TYPE_TEXT;
        $qty = Result::TYPE_DECIMAL;
        $money = Result::TYPE_CURRENCY;

        return new Result(OrderExport::columns([
            'increment_id' => [__('Order #'), $text],
            'created_at' => [__('Created At (store time)'), $text],
            'status' => [__('Status'), $text],
            'customer_email' => [__('Customer Email'), $text],
            'item_id' => [__('Item ID'), Result::TYPE_INT],
            'sku' => [__('SKU'), $text],
            'name' => [__('Name'), $text],
            'product_type' => [__('Type'), $text],
            'qty_ordered' => [__('Qty Ordered'), $qty],
            'qty_invoiced' => [__('Qty Invoiced'), $qty],
            'qty_shipped' => [__('Qty Shipped'), $qty],
            'qty_refunded' => [__('Qty Refunded'), $qty],
            'qty_canceled' => [__('Qty Canceled'), $qty],
            'base_currency_code' => [__('Base Currency'), $text],
            'base_price' => [__('Price'), $money],
            'base_row_total' => [__('Row Total'), $money],
            'base_discount_amount' => [__('Discount'), $money],
            'base_tax_amount' => [__('Tax'), $money],
            'base_row_total_incl_tax' => [__('Row Total Incl. Tax'), $money],
        ]), $rows);
    }
}
