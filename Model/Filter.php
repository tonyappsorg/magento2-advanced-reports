<?php
declare(strict_types=1);

namespace Tonyapps\AdvancedReports\Model;

/**
 * Report filter. Dates are whole days in the report timezone, and $to is inclusive.
 */
class Filter
{
    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';
    public const PERIODS = [self::PERIOD_DAY, self::PERIOD_WEEK, self::PERIOD_MONTH];

    /**
     * @param \DateTimeImmutable $from local midnight in the report timezone
     * @param \DateTimeImmutable $to local midnight of the last included day, same timezone
     * @param int[] $storeIds empty = all store views
     * @param string[] $states order states; empty = all
     */
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $period = self::PERIOD_DAY,
        public readonly array $storeIds = [],
        public readonly array $states = [],
        // Row-level reports stop after this many rows (the admin page); null = all (CSV export).
        public readonly ?int $limit = null
    ) {
    }

    /**
     * @return array{0: string, 1: string} UTC [from, to) for comparing against sales_order.created_at
     */
    public function getUtcRange(): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            $this->from->setTimezone($utc)->format('Y-m-d H:i:s'),
            $this->to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** The same number of days, ending the day before $from. */
    public function getPrevious(): self
    {
        $days = (int)$this->from->diff($this->to)->days;
        $to = $this->from->modify('-1 day');

        return new self($to->modify("-{$days} days"), $to, $this->period, $this->storeIds, $this->states);
    }

    /**
     * Period label for a UTC quarter hour ("Y-m-d H:i") as returned by OrderSelect::UTC_SLOT. Quarter hours,
     * not hours, so zones with :30/:45 offsets (India, Nepal) don't land on the wrong side of local midnight.
     */
    public function getBucket(string $utcSlot): string
    {
        $local = (new \DateTimeImmutable($utcSlot . ':00', new \DateTimeZone('UTC')))
            ->setTimezone($this->from->getTimezone());

        return $this->bucketOf($local);
    }

    /**
     * @return string[] every period label in the range, in order, so charts have no gaps
     */
    public function getBuckets(): array
    {
        $buckets = [];
        for ($day = $this->from; $day <= $this->to; $day = $day->modify('+1 day')) {
            $buckets[$this->bucketOf($day)] = true;
        }

        return array_keys($buckets);
    }

    private function bucketOf(\DateTimeImmutable $local): string
    {
        return match ($this->period) {
            self::PERIOD_WEEK => $local->modify('monday this week')->format('Y-m-d'),
            self::PERIOD_MONTH => $local->format('Y-m'),
            default => $local->format('Y-m-d'),
        };
    }
}
