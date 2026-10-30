<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Builds a Filter from untrusted request params (admin GET, REST query, email config).
 */
class FilterFactory
{
    private const DEFAULT_DAYS = 30;
    private const MAX_DAYS = 1100;

    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly OrderConfig $orderConfig
    ) {
    }

    /**
     * @param array<string, mixed> $params from, to (Y-m-d), period, store_ids[], states[]
     * @throws LocalizedException
     */
    public function create(array $params = []): Filter
    {
        $tz = new \DateTimeZone($this->timezone->getConfigTimezone());
        $today = new \DateTimeImmutable('today', $tz);

        $to = $this->parseDate($params['to'] ?? null, $tz) ?? $today;
        $from = $this->parseDate($params['from'] ?? null, $tz) ?? $to->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diff($to)->days > self::MAX_DAYS) {
            throw new LocalizedException(__('The date range can be at most %1 days.', self::MAX_DAYS));
        }

        $period = in_array($params['period'] ?? null, Filter::PERIODS, true) ? $params['period'] : Filter::PERIOD_DAY;

        $storeIds = array_values(array_filter(array_map('intval', (array)($params['store_ids'] ?? []))));

        $allStates = array_keys($this->orderConfig->getStates());
        $states = array_values(array_intersect((array)($params['states'] ?? []), $allStates));
        if (!isset($params['states'])) {
            // Not yet (or never) paid orders aren't revenue.
            $states = array_values(array_diff(
                $allStates,
                [Order::STATE_CANCELED, Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW]
            ));
        }

        return new Filter($from, $to, $period, $storeIds, $states);
    }

    private function parseDate(mixed $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
