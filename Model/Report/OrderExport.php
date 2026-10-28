<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model\Report;

use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Tonyapps\AdvancedReports\Model\Filter;
use Tonyapps\AdvancedReports\Model\OrderSelect;
use Tonyapps\AdvancedReports\Model\ReportInterface;
use Tonyapps\AdvancedReports\Model\Result;

/**
 * One row per order, for ERP/accounting import. Base-currency row values, no totals row.
 */
class OrderExport implements ReportInterface
{
    private const MONEY = [
        'base_subtotal', 'base_discount_amount', 'base_shipping_amount', 'base_tax_amount',
        'base_grand_total', 'base_total_refunded', 'grand_total',
    ];

    public function __construct(
        private readonly OrderSelect $orderSelect,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function getTitle(): string
    {
        return (string)__('Order Export');
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

    /** @param Select $orders a sales_order select aliased "o" with no columns (OrderSelect::create / createIdRange) */
    public function fromSelect(Select $orders): Result
    {
        $address = $this->orderSelect->getTable('sales_order_address');
        $select = $orders
            ->joinLeft(['b' => $address], "b.parent_id = o.entity_id AND b.address_type = 'billing'", [
                'billing_firstname' => 'b.firstname', 'billing_lastname' => 'b.lastname',
                'billing_company' => 'b.company', 'billing_street' => 'b.street', 'billing_city' => 'b.city',
                'billing_region' => 'b.region', 'billing_postcode' => 'b.postcode',
                'billing_country' => 'b.country_id', 'billing_phone' => 'b.telephone', 'billing_vat' => 'b.vat_id',
            ])
            ->joinLeft(['s' => $address], "s.parent_id = o.entity_id AND s.address_type = 'shipping'", [
                'shipping_firstname' => 's.firstname', 'shipping_lastname' => 's.lastname',
                'shipping_street' => 's.street', 'shipping_city' => 's.city', 'shipping_region' => 's.region',
                'shipping_postcode' => 's.postcode', 'shipping_country' => 's.country_id',
            ])
            ->joinLeft(['p' => $this->orderSelect->getTable('sales_order_payment')], 'p.parent_id = o.entity_id', [
                'payment_method' => 'p.method',
            ])
            ->joinLeft(['st' => $this->orderSelect->getTable('store')], 'st.store_id = o.store_id', [
                'store' => 'st.code',
            ])
            ->joinLeft(['g' => $this->orderSelect->getTable('customer_group')], 'g.customer_group_id = o.customer_group_id', [
                'customer_group' => 'g.customer_group_code',
            ])
            ->columns([
                'increment_id' => 'o.increment_id', 'created_at' => 'o.created_at', 'state' => 'o.state',
                'status' => 'o.status', 'customer_email' => 'o.customer_email',
                'customer_firstname' => 'o.customer_firstname', 'customer_lastname' => 'o.customer_lastname',
                'customer_is_guest' => 'o.customer_is_guest', 'shipping_method' => 'o.shipping_description',
                'coupon_code' => 'o.coupon_code', 'total_qty_ordered' => 'o.total_qty_ordered',
                'base_currency_code' => 'o.base_currency_code', 'base_subtotal' => 'o.base_subtotal',
                'base_discount_amount' => 'o.base_discount_amount', 'base_shipping_amount' => 'o.base_shipping_amount',
                'base_tax_amount' => 'o.base_tax_amount', 'base_grand_total' => 'o.base_grand_total',
                'base_total_refunded' => 'o.base_total_refunded', 'order_currency_code' => 'o.order_currency_code',
                'grand_total' => 'o.grand_total',
            ])
            ->order('o.entity_id ASC');

        $utc = new \DateTimeZone('UTC');
        $local = new \DateTimeZone($this->timezone->getConfigTimezone());
        $rows = [];
        foreach ($this->orderSelect->getConnection()->fetchAll($select) as $row) {
            $row['created_at'] = (new \DateTimeImmutable($row['created_at'], $utc))->setTimezone($local)->format('Y-m-d H:i:s');
            $row['customer_name'] = trim($row['customer_firstname'] . ' ' . $row['customer_lastname']);
            $row['customer_is_guest'] = $row['customer_is_guest'] ? 1 : 0;
            $row['total_qty_ordered'] = (float)$row['total_qty_ordered'];
            $row['billing_name'] = trim($row['billing_firstname'] . ' ' . $row['billing_lastname']);
            $row['shipping_name'] = trim($row['shipping_firstname'] . ' ' . $row['shipping_lastname']);
            $row['billing_street'] = self::street($row['billing_street']);
            $row['shipping_street'] = self::street($row['shipping_street']);
            foreach (self::MONEY as $key) {
                $row[$key] = round((float)$row[$key], 2);
            }
            $rows[] = $row;
        }

        $text = Result::TYPE_TEXT;
        $money = Result::TYPE_CURRENCY;

        return new Result(self::columns([
            'increment_id' => [__('Order #'), $text],
            'created_at' => [__('Created At (store time)'), $text],
            'store' => [__('Store'), $text],
            'state' => [__('State'), $text],
            'status' => [__('Status'), $text],
            'customer_email' => [__('Customer Email'), $text],
            'customer_name' => [__('Customer Name'), $text],
            'customer_group' => [__('Customer Group'), $text],
            'customer_is_guest' => [__('Guest'), Result::TYPE_INT],
            'billing_name' => [__('Billing Name'), $text],
            'billing_company' => [__('Billing Company'), $text],
            'billing_street' => [__('Billing Street'), $text],
            'billing_city' => [__('Billing City'), $text],
            'billing_region' => [__('Billing Region'), $text],
            'billing_postcode' => [__('Billing Postcode'), $text],
            'billing_country' => [__('Billing Country'), $text],
            'billing_phone' => [__('Billing Phone'), $text],
            'billing_vat' => [__('Billing VAT'), $text],
            'shipping_name' => [__('Shipping Name'), $text],
            'shipping_street' => [__('Shipping Street'), $text],
            'shipping_city' => [__('Shipping City'), $text],
            'shipping_region' => [__('Shipping Region'), $text],
            'shipping_postcode' => [__('Shipping Postcode'), $text],
            'shipping_country' => [__('Shipping Country'), $text],
            'shipping_method' => [__('Shipping Method'), $text],
            'payment_method' => [__('Payment Method'), $text],
            'coupon_code' => [__('Coupon'), $text],
            'total_qty_ordered' => [__('Items Qty'), Result::TYPE_DECIMAL],
            'base_currency_code' => [__('Base Currency'), $text],
            'base_subtotal' => [__('Subtotal'), $money],
            'base_discount_amount' => [__('Discount'), $money],
            'base_shipping_amount' => [__('Shipping'), $money],
            'base_tax_amount' => [__('Tax'), $money],
            'base_grand_total' => [__('Grand Total'), $money],
            'base_total_refunded' => [__('Refunded'), $money],
            'order_currency_code' => [__('Order Currency'), $text],
            'grand_total' => [__('Grand Total Charged'), Result::TYPE_DECIMAL],
        ]), $rows);
    }

    /**
     * @param array<string, array{0: \Stringable|string, 1: string}> $spec
     * @return array<string, array{label: string, type: string}>
     */
    public static function columns(array $spec): array
    {
        return array_map(static fn (array $c) => ['label' => (string)$c[0], 'type' => $c[1]], $spec);
    }

    /** Multi-line street to one CSV-friendly line. */
    private static function street(?string $street): string
    {
        return implode(', ', array_filter(array_map('trim', explode("\n", (string)$street)), 'strlen'));
    }
}
